<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service\Reputation;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Service\ProviderToolsClient;
use OCA\SouveraShield\Service\ProviderToolsException;
use Psr\Log\LoggerInterface;

/**
 * Derives higher-level insights from the DMARC aggregate reports that
 * provider.tools already collects for the managed domain:
 *
 *   - provider-specific reputation (Google, Microsoft, Yahoo, GMX/Web.de)
 *     from the reports each provider sent about our mail
 *   - classification of sending sources (legitimate / unknown /
 *     potentially abusive) from the top-senders statistics
 *   - volume anomalies (possible compromised account / spam burst)
 *
 * Everything is computed from real report data – when there are no
 * reports for the period the caller gets empty lists, never fake values.
 */
class DmarcInsightService {

    private const MAX_REPORT_PAGES = 5;
    private const REPORTS_PER_PAGE = 100;

    /** provider bucket → matching substrings (lowercase) */
    private const PROVIDER_PATTERNS = [
        'google'    => ['google'],
        'microsoft' => ['microsoft', 'outlook', 'hotmail', 'office365', 'office 365'],
        'yahoo'     => ['yahoo', 'aol', 'verizon'],
        'gmx_webde' => ['gmx', 'web.de', 'webde', '1&1', '1und1', 'ionos', 'united internet', 'mail.com'],
    ];

    private const PROVIDER_LABELS = [
        'google'    => 'Google (Gmail)',
        'microsoft' => 'Microsoft (Outlook/Hotmail)',
        'yahoo'     => 'Yahoo/AOL',
        'gmx_webde' => 'GMX/Web.de',
        'other'     => 'Other providers',
    ];

    public function __construct(
        private readonly ProviderToolsClient $provider,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *   days:int,
     *   reports_analyzed:int,
     *   providers:array<int,array<string,mixed>>,
     *   sources:array<int,array<string,mixed>>,
     *   anomalies:array<int,array<string,mixed>>,
     *   stats:array<string,mixed>
     * }
     */
    public function collect(string $providerDomainId, int $days = 30): array {
        $stats = [];
        try {
            $stats = $this->provider->getDomainStats($providerDomainId, $days);
        } catch (ProviderToolsException $e) {
            $this->logger->warning('DMARC stats unavailable for insights', [
                'app' => Application::APP_ID, 'error' => $e->getMessage(),
            ]);
        }

        $reports = $this->fetchReports($providerDomainId, $days);

        return [
            'days'             => $days,
            'reports_analyzed' => count($reports),
            'providers'        => $this->providerBreakdown($reports),
            'sources'          => $this->classifySources($stats),
            'anomalies'        => $this->detectAnomalies($reports, $stats),
            'stats'            => $stats,
        ];
    }

    // -------------------------------------------------------------------
    // Provider-specific reputation
    // -------------------------------------------------------------------

    /**
     * @param array<int,array<string,mixed>> $reports
     * @return array<int,array<string,mixed>>
     */
    public function providerBreakdown(array $reports): array {
        $buckets = [];
        foreach (array_merge(array_keys(self::PROVIDER_PATTERNS), ['other']) as $key) {
            $buckets[$key] = [
                'key'          => $key,
                'label'        => self::PROVIDER_LABELS[$key],
                'reports'      => 0,
                'messages'     => 0,
                'dkim_passed'  => 0,
                'spf_passed'   => 0,
                'last_report'  => null,
                'org_names'    => [],
            ];
        }

        foreach ($reports as $report) {
            $org = (string)($report['orgName'] ?? '');
            $bucket = $this->matchProvider($org);
            $messages = (int)($report['totalMessages'] ?? 0);
            $buckets[$bucket]['reports']++;
            $buckets[$bucket]['messages']    += $messages;
            $buckets[$bucket]['dkim_passed'] += (int)($report['passedDkim'] ?? 0);
            $buckets[$bucket]['spf_passed']  += (int)($report['passedSpf'] ?? 0);
            $end = $this->toTimestamp($report['dateRangeEnd'] ?? null);
            if ($end !== null && ($buckets[$bucket]['last_report'] === null || $end > $buckets[$bucket]['last_report'])) {
                $buckets[$bucket]['last_report'] = $end;
            }
            if ($org !== '' && !in_array($org, $buckets[$bucket]['org_names'], true)) {
                $buckets[$bucket]['org_names'][] = $org;
            }
        }

        $out = [];
        foreach ($buckets as $bucket) {
            $messages = $bucket['messages'];
            $dkimRate = $messages > 0 ? (int)round($bucket['dkim_passed'] / $messages * 100) : null;
            $spfRate  = $messages > 0 ? (int)round($bucket['spf_passed'] / $messages * 100) : null;
            $aligned  = null;
            if ($dkimRate !== null && $spfRate !== null) {
                $aligned = max($dkimRate, $spfRate);
            }
            $verdict = 'nodata';
            if ($aligned !== null) {
                $verdict = $aligned >= 95 ? 'ok' : ($aligned >= 80 ? 'warn' : 'critical');
            }
            $out[] = [
                'key'           => $bucket['key'],
                'label'         => $bucket['label'],
                'reports'       => $bucket['reports'],
                'messages'      => $messages,
                'dkimPassRate'  => $dkimRate,
                'spfPassRate'   => $spfRate,
                'alignedRate'   => $aligned,
                'verdict'       => $verdict,
                'lastReportAt'  => $bucket['last_report'],
                'orgNames'      => $bucket['org_names'],
            ];
        }
        return $out;
    }

    // -------------------------------------------------------------------
    // Sending-source classification
    // -------------------------------------------------------------------

    /**
     * Classify the top sending sources from provider.tools statistics as
     * legitimate / unknown / potentially abusive, based on their real
     * SPF/DKIM pass rates and volume.
     *
     * @param array<string,mixed> $stats
     * @return array<int,array<string,mixed>>
     */
    public function classifySources(array $stats): array {
        $sources = [];
        $top = $stats['topSenders'] ?? [];
        if (!is_array($top)) {
            return [];
        }
        foreach ($top as $row) {
            if (!is_array($row)) {
                continue;
            }
            $org      = (string)($row['organization'] ?? $row['orgName'] ?? '?');
            $messages = (int)($row['messages'] ?? 0);
            $dkim = $this->rateOrNull($row['dkimPassRate'] ?? null);
            $spf  = $this->rateOrNull($row['spfPassRate'] ?? null);
            $best = null;
            if ($dkim !== null || $spf !== null) {
                $best = max($dkim ?? 0, $spf ?? 0);
            }

            $classification = 'unknown';
            if ($best !== null) {
                if ($best >= 90) {
                    $classification = 'legitimate';
                } elseif ($best < 20 && $messages >= 10) {
                    $classification = 'abusive';
                }
            }

            $sources[] = [
                'organization' => $org,
                'messages'     => $messages,
                'dkimPassRate' => $dkim,
                'spfPassRate'  => $spf,
                'alignedRate'  => $best,
                'classification' => $classification,
            ];
        }
        usort($sources, static fn(array $a, array $b) => $b['messages'] <=> $a['messages']);
        return $sources;
    }

    // -------------------------------------------------------------------
    // Anomaly detection
    // -------------------------------------------------------------------

    /**
     * Detects unusual sending behaviour from real report data:
     *   - volume spikes (daily volume > mean + 3σ, min. 100 messages)
     *   - sources that fail all authentication with significant volume
     *
     * @param array<int,array<string,mixed>> $reports
     * @param array<string,mixed> $stats
     * @return array<int,array<string,mixed>>
     */
    public function detectAnomalies(array $reports, array $stats): array {
        $anomalies = [];

        // Daily volume series from report date ranges.
        $daily = [];
        foreach ($reports as $report) {
            $begin = $this->toTimestamp($report['dateRangeBegin'] ?? null);
            if ($begin === null) {
                continue;
            }
            $day = gmdate('Y-m-d', $begin);
            $daily[$day] = ($daily[$day] ?? 0) + (int)($report['totalMessages'] ?? 0);
        }
        if (count($daily) >= 5) {
            $values = array_values($daily);
            $maxDay = array_keys($daily, max($values), true)[0];
            $maxVal = max($values);
            $rest = $values;
            unset($rest[array_search($maxVal, $rest, true)]);
            $mean = array_sum($rest) / max(1, count($rest));
            $variance = 0.0;
            foreach ($rest as $v) {
                $variance += ($v - $mean) ** 2;
            }
            $std = sqrt($variance / max(1, count($rest)));
            if ($maxVal >= 100 && $maxVal > $mean + 3 * max(1.0, $std)) {
                $anomalies[] = [
                    'type'     => 'volume_spike',
                    'day'      => $maxDay,
                    'messages' => $maxVal,
                    'baseline' => (int)round($mean),
                ];
            }
        }

        // Fully unauthenticated sources with real volume.
        foreach ($this->classifySources($stats) as $source) {
            if ($source['classification'] === 'abusive') {
                $anomalies[] = [
                    'type'         => 'abusive_source',
                    'organization' => $source['organization'],
                    'messages'     => $source['messages'],
                    'alignedRate'  => $source['alignedRate'],
                ];
            }
        }

        return $anomalies;
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchReports(string $providerDomainId, int $days): array {
        $cutoff = time() - $days * 86400;
        $all = [];
        for ($page = 1; $page <= self::MAX_REPORT_PAGES; $page++) {
            try {
                $data = $this->provider->listAggregateReports($providerDomainId, $page, self::REPORTS_PER_PAGE);
            } catch (ProviderToolsException $e) {
                $this->logger->warning('Aggregate reports unavailable for insights', [
                    'app' => Application::APP_ID, 'error' => $e->getMessage(),
                ]);
                break;
            }
            $rows = $data['reports'] ?? [];
            if (!is_array($rows) || $rows === []) {
                break;
            }
            $reachedCutoff = false;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $begin = $this->toTimestamp($row['dateRangeBegin'] ?? null);
                if ($begin !== null && $begin < $cutoff) {
                    $reachedCutoff = true;
                    continue;
                }
                $all[] = $row;
            }
            $totalPages = (int)($data['pagination']['totalPages'] ?? $page);
            if ($reachedCutoff || $page >= $totalPages) {
                break;
            }
        }
        return $all;
    }

    private function matchProvider(string $orgName): string {
        $needle = strtolower($orgName);
        foreach (self::PROVIDER_PATTERNS as $key => $patterns) {
            foreach ($patterns as $pattern) {
                if ($needle !== '' && str_contains($needle, $pattern)) {
                    return $key;
                }
            }
        }
        return 'other';
    }

    private function toTimestamp(mixed $value): ?int {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $n = (int)$value;
            return $n > 10_000_000_000 ? (int)($n / 1000) : $n;
        }
        if (is_string($value) && $value !== '') {
            $ts = strtotime($value);
            return $ts !== false ? $ts : null;
        }
        return null;
    }

    private function rateOrNull(mixed $value): ?int {
        if (!is_numeric($value)) {
            return null;
        }
        return max(0, min(100, (int)round((float)$value)));
    }
}
