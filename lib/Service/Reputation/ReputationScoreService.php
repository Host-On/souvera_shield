<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service\Reputation;

use OCA\SouveraShield\Db\DmarcDomain;
use OCA\SouveraShield\Db\MailTest;
use OCA\SouveraShield\Db\MailTestMapper;
use OCA\SouveraShield\Db\RepIncident;
use OCA\SouveraShield\Db\RepIncidentMapper;
use OCA\SouveraShield\Db\RepSnapshot;
use OCA\SouveraShield\Db\RepSnapshotMapper;
use Psr\Log\LoggerInterface;

/**
 * Computes the composite reputation score (0–100) from the real data
 * sources the app already has – and *only* from components for which
 * data actually exists. Missing components are excluded and the weights
 * re-normalised; when nothing is available the score is `null`
 * ("insufficient data"), never an invented number.
 *
 * Components and base weights:
 *   dmarc          30  – DKIM/SPF pass rates from aggregate reports
 *                        (0.7 × better rate + 0.3 × weaker rate: one
 *                        aligned mechanism is enough for DMARC, but both
 *                        passing is healthier)
 *   mail_test      25  – latest completed provider.tools test (0–10 → ×10)
 *   blacklist      20  – DNSBL listings of outbound IP + domain
 *                        (−40 per critical listing, −15 per other)
 *   infrastructure 15  – ok/warn/fail ratio of the deliverability checks
 *   incidents      10  – open incidents (−30 critical, −10 warning)
 */
class ReputationScoreService {

    private const WEIGHTS = [
        'dmarc'          => 30,
        'mail_test'      => 25,
        'blacklist'      => 20,
        'infrastructure' => 15,
        'incidents'      => 10,
    ];

    private const SNAPSHOT_MIN_AGE = 20 * 3600;

    public function __construct(
        private readonly RepSnapshotMapper $snapshots,
        private readonly RepIncidentMapper $incidents,
        private readonly MailTestMapper $tests,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $stats  provider.tools domain stats (may be empty)
     * @param array<string,mixed> $checks result of DeliverabilityCheckService::getChecks()
     * @return array{score:?int, state:string, components:array<int,array<string,mixed>>}
     */
    public function compute(DmarcDomain $domain, array $stats, array $checks): array {
        $components = [];

        $components[] = $this->dmarcComponent($stats);
        $components[] = $this->mailTestComponent((int)$domain->getId());
        $components[] = $this->blacklistComponent($checks);
        $components[] = $this->infrastructureComponent($checks);
        $components[] = $this->incidentsComponent($domain->getDomain());

        $weightSum = 0;
        $scoreSum  = 0.0;
        foreach ($components as $component) {
            if (!$component['available']) {
                continue;
            }
            $weightSum += $component['weight'];
            $scoreSum  += $component['score'] * $component['weight'];
        }

        if ($weightSum === 0) {
            return ['score' => null, 'state' => 'insufficient_data', 'components' => $components];
        }
        $score = (int)round($scoreSum / $weightSum);
        $score = max(0, min(100, $score));
        return ['score' => $score, 'state' => 'ok', 'components' => $components];
    }

    /**
     * Persist at most one snapshot per ~day so the history stays clean.
     *
     * @param array{score:?int, state:string, components:array<int,array<string,mixed>>} $result
     */
    public function snapshot(string $domain, array $result): void {
        $latest = $this->snapshots->findLatest($domain);
        if ($latest !== null && (time() - (int)$latest->getCreatedAt()) < self::SNAPSHOT_MIN_AGE) {
            return;
        }
        $snap = new RepSnapshot();
        $snap->setDomainName($domain);
        $snap->setScore($result['score']);
        $snap->setComponents(json_encode($result['components'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $snap->setCreatedAt(time());
        $this->snapshots->insert($snap);
    }

    /**
     * @return array<int,array{ts:int, score:?int}> oldest first
     */
    public function history(string $domain, int $limit = 30): array {
        $rows = $this->snapshots->findRecent($domain, $limit);
        $out = [];
        foreach (array_reverse($rows) as $row) {
            $out[] = ['ts' => (int)$row->getCreatedAt(), 'score' => $row->getScore()];
        }
        return $out;
    }

    // -------------------------------------------------------------------
    // Components
    // -------------------------------------------------------------------

    /**
     * @param array<string,mixed> $stats
     * @return array<string,mixed>
     */
    private function dmarcComponent(array $stats): array {
        $messages = (int)($stats['totalMessages'] ?? 0);
        $dkim = is_numeric($stats['dkimPassRate'] ?? null) ? (float)$stats['dkimPassRate'] : null;
        $spf  = is_numeric($stats['spfPassRate'] ?? null) ? (float)$stats['spfPassRate'] : null;
        if ($messages <= 0 || ($dkim === null && $spf === null)) {
            return $this->component('dmarc', false, 0, ['reason' => 'no_report_data']);
        }
        $best  = max($dkim ?? 0.0, $spf ?? 0.0);
        $worst = min($dkim ?? $best, $spf ?? $best);
        $score = 0.7 * $best + 0.3 * $worst;
        return $this->component('dmarc', true, $score, [
            'messages' => $messages,
            'dkimPassRate' => $dkim,
            'spfPassRate' => $spf,
        ]);
    }

    /** @return array<string,mixed> */
    private function mailTestComponent(int $domainId): array {
        $latest = null;
        foreach ($this->tests->findRecent(50, $domainId) as $t) {
            if ($t->getStatus() === MailTest::STATUS_COMPLETED && $t->getScore() !== null) {
                $latest = $t;
                break;
            }
        }
        if ($latest === null) {
            return $this->component('mail_test', false, 0, ['reason' => 'no_completed_mail_test']);
        }
        $raw = (float)$latest->getScore();
        // provider.tools scores 0–10 (mail-tester style); normalise defensively.
        $score = $raw <= 10.0 ? $raw * 10.0 : $raw;
        $score = max(0.0, min(100.0, $score));
        return $this->component('mail_test', true, $score, [
            'raw_score'    => $raw,
            'test_id'      => $latest->getTestId(),
            'completed_at' => $latest->getCompletedAt(),
        ]);
    }

    /**
     * @param array<string,mixed> $checks
     * @return array<string,mixed>
     */
    private function blacklistComponent(array $checks): array {
        $critical = 0;
        $other = 0;
        $sawData = false;
        foreach (($checks['checks'] ?? []) as $check) {
            if (!in_array($check['id'] ?? '', ['blacklist_ip', 'blacklist_domain'], true)) {
                continue;
            }
            if (($check['status'] ?? 'nodata') === 'nodata') {
                continue;
            }
            $sawData = true;
            foreach (($check['observed']['listed'] ?? []) as $listing) {
                if (strtolower((string)($listing['category'] ?? '')) === 'critical') {
                    $critical++;
                } else {
                    $other++;
                }
            }
        }
        if (!$sawData) {
            return $this->component('blacklist', false, 0, ['reason' => 'no_blacklist_data']);
        }
        $score = max(0, 100 - 40 * $critical - 15 * $other);
        return $this->component('blacklist', true, (float)$score, [
            'critical_listings' => $critical,
            'other_listings'    => $other,
        ]);
    }

    /**
     * @param array<string,mixed> $checks
     * @return array<string,mixed>
     */
    private function infrastructureComponent(array $checks): array {
        $points = 0.0;
        $counted = 0;
        foreach (($checks['checks'] ?? []) as $check) {
            $id = (string)($check['id'] ?? '');
            if (in_array($id, ['blacklist_ip', 'blacklist_domain'], true)) {
                continue;
            }
            switch ($check['status'] ?? 'nodata') {
                case 'ok':
                    $points += 1.0;
                    $counted++;
                    break;
                case 'warn':
                    $points += 0.5;
                    $counted++;
                    break;
                case 'fail':
                    $counted++;
                    break;
                default:
                    // info / nodata → not scored
                    break;
            }
        }
        if ($counted === 0) {
            return $this->component('infrastructure', false, 0, ['reason' => 'no_check_data']);
        }
        return $this->component('infrastructure', true, $points / $counted * 100.0, [
            'checks_scored' => $counted,
        ]);
    }

    /** @return array<string,mixed> */
    private function incidentsComponent(string $domain): array {
        $criticalOpen = 0;
        $warningOpen = 0;
        foreach ($this->incidents->findOpen($domain) as $incident) {
            if ($incident->getSeverity() === RepIncident::SEVERITY_CRITICAL) {
                $criticalOpen++;
            } elseif ($incident->getSeverity() === RepIncident::SEVERITY_WARNING) {
                $warningOpen++;
            }
        }
        $score = max(0, 100 - 30 * $criticalOpen - 10 * $warningOpen);
        return $this->component('incidents', true, (float)$score, [
            'open_critical' => $criticalOpen,
            'open_warning'  => $warningOpen,
        ]);
    }

    /**
     * @param array<string,mixed> $detail
     * @return array<string,mixed>
     */
    private function component(string $id, bool $available, float $score, array $detail): array {
        return [
            'id'        => $id,
            'available' => $available,
            'score'     => $available ? (int)round(max(0.0, min(100.0, $score))) : null,
            'weight'    => self::WEIGHTS[$id],
            'detail'    => $detail,
        ];
    }
}
