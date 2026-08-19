<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service\Reputation;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Db\DmarcDomain;
use OCA\SouveraShield\Db\MailTest;
use OCA\SouveraShield\Db\RepIncident;
use OCA\SouveraShield\Db\RepIncidentMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Detects, deduplicates and maintains reputation incidents.
 *
 * Detection sources (all real data):
 *   - failed deliverability checks (blacklists, DNS, TLS, alignment …)
 *   - DMARC pass-rate drops from aggregate reports
 *   - volume anomalies / potentially abusive sources (DmarcInsightService)
 *   - failed or poor mail-tests incl. the interpreted SMTP diagnosis
 *
 * Lifecycle: re-detected incidents update the existing row; incidents
 * whose condition is no longer detected are auto-resolved with a note in
 * the measures log. Every state change appends to `measures`, giving the
 * complete history and executed-actions trail.
 *
 * ─── v3.9.0 — English source strings + IL10N ───
 *
 * All user-visible strings (titles, descriptions, recommendations,
 * measure notes) are wrapped in {@see IL10N::t()} with English source
 * strings so DE and NL translations kick in when available and the
 * fallback is always English.
 */
class IncidentService {

    private readonly IL10N $l10n;

    public function __construct(
        private readonly RepIncidentMapper $mapper,
        private readonly LoggerInterface $logger,
        IFactory $l10nFactory,
    ) {
        $this->l10n = $l10nFactory->get(Application::APP_ID);
    }

    // -------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------

    /**
     * Run all detectors and reconcile the incident table.
     *
     * @param array<string,mixed> $checks   DeliverabilityCheckService result
     * @param array<string,mixed> $insights DmarcInsightService result (may be empty)
     * @param MailTest[]          $recentTests newest first
     * @return array{raised:int, updated:int, auto_resolved:int, open:int}
     */
    public function runDetection(DmarcDomain $domain, array $checks, array $insights, array $recentTests): array {
        $domainName = $domain->getDomain();
        $seen = [];
        $raised = 0;
        $updated = 0;

        // 1) Failed deliverability checks --------------------------------
        foreach (($checks['checks'] ?? []) as $check) {
            if (($check['status'] ?? '') !== 'fail') {
                continue;
            }
            $id = (string)$check['id'];
            $isBlacklist = str_starts_with($id, 'blacklist_');
            $key = 'infra:' . $id;
            $seen[] = $key;
            $result = $this->raise(
                dedupeKey:      $key,
                domainName:     $domainName,
                severity:       $isBlacklist ? RepIncident::SEVERITY_CRITICAL : RepIncident::SEVERITY_WARNING,
                category:       $isBlacklist ? RepIncident::CATEGORY_BLACKLIST : RepIncident::CATEGORY_INFRA,
                title:          $this->checkTitle($id),
                description:    $this->describeCheckFailure($id, $check),
                recommendation: $this->checkRecommendation($id),
                evidence:       ['check' => $check, 'domain' => $domainName],
            );
            $result === 'raised' ? $raised++ : $updated++;
        }

        // 2) DMARC pass rate ---------------------------------------------
        $stats = $insights['stats'] ?? [];
        $messages = (int)($stats['totalMessages'] ?? 0);
        $dkim = is_numeric($stats['dkimPassRate'] ?? null) ? (float)$stats['dkimPassRate'] : null;
        $spf  = is_numeric($stats['spfPassRate'] ?? null) ? (float)$stats['spfPassRate'] : null;
        if ($messages >= 10 && ($dkim !== null || $spf !== null)) {
            $aligned = max($dkim ?? 0.0, $spf ?? 0.0);
            if ($aligned < 80.0) {
                $key = 'auth:dmarc-passrate';
                $seen[] = $key;
                $severity = $aligned < 50.0 ? RepIncident::SEVERITY_CRITICAL : RepIncident::SEVERITY_WARNING;
                $na = $this->l10n->t('n/a');
                $result = $this->raise(
                    dedupeKey:      $key,
                    domainName:     $domainName,
                    severity:       $severity,
                    category:       RepIncident::CATEGORY_AUTH,
                    title:          $this->l10n->t('Low DMARC pass rate (%d %%)', [(int)round($aligned)]),
                    description:    $this->l10n->t(
                        'Only %1$d %% of %2$d reported messages pass DMARC (DKIM %3$s, SPF %4$s). '
                        . 'Recipients like Google and Microsoft increasingly classify unauthenticated '
                        . 'mail as spam or reject it outright.',
                        [
                            (int)round($aligned),
                            $messages,
                            $dkim !== null ? (int)round($dkim) . ' %' : $na,
                            $spf !== null ? (int)round($spf) . ' %' : $na,
                        ],
                    ),
                    recommendation: $this->l10n->t(
                        'Verify DKIM signing and SPF authorisation for every sending path. The source '
                        . 'classification shows which senders fail the checks.'
                    ),
                    evidence:       ['stats' => $stats, 'domain' => $domainName],
                );
                $result === 'raised' ? $raised++ : $updated++;
            }
        }

        // 3) Anomalies (possible compromise / spam burst) ----------------
        foreach (($insights['anomalies'] ?? []) as $anomaly) {
            if (($anomaly['type'] ?? '') === 'volume_spike') {
                $day = (string)($anomaly['day'] ?? '?');
                $key = 'anomaly:spike:' . $day;
                $seen[] = $key;
                $result = $this->raise(
                    dedupeKey:      $key,
                    domainName:     $domainName,
                    severity:       RepIncident::SEVERITY_CRITICAL,
                    category:       RepIncident::CATEGORY_ANOMALY,
                    title:          $this->l10n->t('Unusual sending spike on %s', [$day]),
                    description:    $this->l10n->t(
                        'On %1$s %2$s messages were reported – significantly above the usual daily '
                        . 'volume of ~%3$s. A sudden volume spike may indicate a compromised account '
                        . 'or an abused script.',
                        [$day, (string)($anomaly['messages'] ?? '?'), (string)($anomaly['baseline'] ?? '?')],
                    ),
                    recommendation: $this->l10n->t(
                        'Review the sending logs for that day, identify unusual sender accounts, '
                        . 'reset affected passwords and revoke app-passwords where needed.'
                    ),
                    evidence:       ['anomaly' => $anomaly, 'domain' => $domainName],
                );
                $result === 'raised' ? $raised++ : $updated++;
            } elseif (($anomaly['type'] ?? '') === 'abusive_source') {
                $org = (string)($anomaly['organization'] ?? '?');
                $key = 'abuse:' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $org) ?? $org);
                $seen[] = $key;
                $msgs = (int)($anomaly['messages'] ?? 0);
                $result = $this->raise(
                    dedupeKey:      $key,
                    domainName:     $domainName,
                    severity:       $msgs >= 50 ? RepIncident::SEVERITY_CRITICAL : RepIncident::SEVERITY_WARNING,
                    category:       RepIncident::CATEGORY_ABUSE,
                    title:          $this->l10n->t('Potentially abusive sending source: %s', [$org]),
                    description:    $this->l10n->t(
                        'The source "%1$s" sent %2$d messages on behalf of domain %3$s without '
                        . 'passing SPF or DKIM (%4$d %% pass rate). This may be spoofing/phishing '
                        . 'in the name of the domain – or a legitimate service that is not yet '
                        . 'configured for SPF/DKIM.',
                        [$org, $msgs, $domainName, (int)($anomaly['alignedRate'] ?? 0)],
                    ),
                    recommendation: $this->l10n->t(
                        'Check whether the source is legitimate. If legitimate → add SPF include / '
                        . 'DKIM key. If not → tighten DMARC policy to p=reject so recipients reject '
                        . 'these messages.'
                    ),
                    evidence:       ['anomaly' => $anomaly, 'domain' => $domainName],
                );
                $result === 'raised' ? $raised++ : $updated++;
            }
        }

        // 4) Mail-test problems (incl. understandable SMTP diagnosis) ----
        $latestError = null;
        $latestCompleted = null;
        foreach ($recentTests as $test) {
            if ($latestError === null && $test->getStatus() === MailTest::STATUS_ERROR) {
                $latestError = $test;
            }
            if ($latestCompleted === null && $test->getStatus() === MailTest::STATUS_COMPLETED) {
                $latestCompleted = $test;
            }
            if ($latestError !== null && $latestCompleted !== null) {
                break;
            }
        }
        // Only raise a dispatch incident when the error is newer than the last success.
        if ($latestError !== null
            && ($latestCompleted === null || (int)$latestError->getCreatedAt() > (int)$latestCompleted->getCreatedAt())) {
            $key = 'mailtest:dispatch';
            $seen[] = $key;
            $errorMessage = (string)($latestError->getErrorMessage() ?? '');
            if ($errorMessage === '') {
                $errorMessage = $this->l10n->t('Unknown error while dispatching the test mail.');
            }
            $result = $this->raise(
                dedupeKey:      $key,
                domainName:     $domainName,
                severity:       RepIncident::SEVERITY_WARNING,
                category:       RepIncident::CATEGORY_MAILTEST,
                title:          $this->l10n->t('Last reputation mail-test failed'),
                description:    $errorMessage,
                recommendation: $this->l10n->t(
                    'The diagnosis above names the failed SMTP stage and the configuration item to check. '
                    . 'After fixing it, start a new mail test.'
                ),
                evidence:       ['test_id' => $latestError->getTestId(), 'created_at' => $latestError->getCreatedAt(), 'domain' => $domainName],
            );
            $result === 'raised' ? $raised++ : $updated++;
        }
        if ($latestCompleted !== null && $latestCompleted->getScore() !== null
            && (float)$latestCompleted->getScore() < 5.0) {
            $key = 'mailtest:score';
            $seen[] = $key;
            $na = $this->l10n->t('n/a');
            $scoreStr = number_format((float)$latestCompleted->getScore(), 1);
            $result = $this->raise(
                dedupeKey:      $key,
                domainName:     $domainName,
                severity:       RepIncident::SEVERITY_WARNING,
                category:       RepIncident::CATEGORY_MAILTEST,
                title:          $this->l10n->t('Weak mail-test score (%s/10)', [$scoreStr]),
                description:    $this->l10n->t(
                    'The most recent completed reputation test scored only %1$s of 10 points '
                    . '(SPF: %2$s, DKIM: %3$s, DMARC: %4$s).',
                    [
                        $scoreStr,
                        $latestCompleted->getSpfResult() ?? $na,
                        $latestCompleted->getDkimResult() ?? $na,
                        $latestCompleted->getDmarcResult() ?? $na,
                    ],
                ),
                recommendation: $this->l10n->t(
                    'Open the mail-test detail analysis and fix the criticised points '
                    . '(authentication, spam score, server configuration) one by one.'
                ),
                evidence:       ['test_id' => $latestCompleted->getTestId(), 'score' => $latestCompleted->getScore(), 'domain' => $domainName],
            );
            $result === 'raised' ? $raised++ : $updated++;
        }

        // 5) Auto-resolve incidents whose condition cleared ---------------
        $autoResolved = $this->autoResolveMissing($domainName, $seen);

        $open = count($this->mapper->findOpen($domainName));
        return ['raised' => $raised, 'updated' => $updated, 'auto_resolved' => $autoResolved, 'open' => $open];
    }

    /**
     * @return RepIncident[]
     */
    public function listIncidents(string $domain, ?string $status = null): array {
        return $this->mapper->findByStatus($domain, $status);
    }

    /**
     * @throws DoesNotExistException
     */
    public function resolve(int $id, string $uid): RepIncident {
        $incident = $this->mapper->findById($id);
        if ($incident->getStatus() === RepIncident::STATUS_RESOLVED) {
            return $incident;
        }
        $incident->setStatus(RepIncident::STATUS_RESOLVED);
        $incident->setResolvedAt(time());
        $incident->setResolvedBy($uid);
        $incident->setUpdatedAt(time());
        $incident->appendMeasure($uid, 'resolved', $this->l10n->t('Manually marked as resolved.'));
        return $this->mapper->update($incident);
    }

    // -------------------------------------------------------------------
    // Localised catalog for infrastructure-check incidents
    // -------------------------------------------------------------------

    private function checkTitle(string $id): string {
        return match ($id) {
            'spf_record'       => $this->l10n->t('SPF record missing or invalid'),
            'dmarc_policy'     => $this->l10n->t('DMARC policy missing or too weak'),
            'mta_sts'          => $this->l10n->t('MTA-STS policy not reachable'),
            'tls_rpt'          => $this->l10n->t('TLS-RPT not configured'),
            'bimi'             => $this->l10n->t('BIMI configuration incomplete'),
            'ptr'              => $this->l10n->t('PTR / reverse-DNS record missing'),
            'helo_tls'         => $this->l10n->t('SMTP transport encryption (STARTTLS) missing'),
            'dkim'             => $this->l10n->t('DKIM signature failed'),
            'spf_alignment'    => $this->l10n->t('SPF alignment failed'),
            'dkim_alignment'   => $this->l10n->t('DKIM alignment failed'),
            'one_click_unsub'  => $this->l10n->t('One-Click-Unsubscribe incomplete'),
            'blacklist_ip'     => $this->l10n->t('Outbound IP is on blacklists'),
            'blacklist_domain' => $this->l10n->t('Domain is on blacklists'),
            default            => $this->l10n->t('Check failed: %s', [$id]),
        };
    }

    private function checkRecommendation(string $id): string {
        return match ($id) {
            'spf_record'       => $this->l10n->t(
                'Publish an SPF TXT record (v=spf1 … -all) in the domain DNS and include every '
                . 'legitimate sending IP.'
            ),
            'dmarc_policy'     => $this->l10n->t(
                'Publish a DMARC record at _dmarc.<domain> with p=quarantine or p=reject and a rua= '
                . 'address.'
            ),
            'mta_sts'          => $this->l10n->t(
                'Serve the policy file at https://mta-sts.<domain>/.well-known/mta-sts.txt (check '
                . 'the webserver and certificate).'
            ),
            'tls_rpt'          => $this->l10n->t(
                'Publish the TXT record _smtp._tls.<domain> with v=TLSRPTv1; rua=mailto:….'
            ),
            'bimi'             => $this->l10n->t(
                'For BIMI, DMARC must be p=quarantine or p=reject; then verify the SVG logo and '
                . 'a VMC certificate if needed.'
            ),
            'ptr'              => $this->l10n->t(
                'Ask the IP owner (hoster) to set a PTR record that points at the HELO hostname '
                . '(Forward-Confirmed rDNS).'
            ),
            'helo_tls'         => $this->l10n->t(
                'Enable STARTTLS on the mail server and install a valid certificate.'
            ),
            'dkim'             => $this->l10n->t(
                'Enable DKIM signing in Stalwart and publish the public key as a TXT record '
                . '(<selector>._domainkey.<domain>).'
            ),
            'spf_alignment'    => $this->l10n->t(
                'MAIL FROM (envelope) must use the customer domain; the domain SPF record must '
                . 'authorise the sending IP.'
            ),
            'dkim_alignment'   => $this->l10n->t(
                'The DKIM signature must be d=<customer-domain>, not a third-party domain.'
            ),
            'one_click_unsub'  => $this->l10n->t(
                'Add List-Unsubscribe and List-Unsubscribe-Post (RFC 8058) headers to bulk / '
                . 'newsletter mail.'
            ),
            'blacklist_ip'     => $this->l10n->t(
                'Request delisting at the affected blacklists and fix the root cause '
                . '(spam sending, open relay).'
            ),
            'blacklist_domain' => $this->l10n->t(
                'Request domain delisting and investigate why the domain was listed '
                . '(compromised accounts, spam content).'
            ),
            default            => $this->l10n->t('Review the details in the check area of the reputation page.'),
        };
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    /**
     * Create a new incident or refresh/reopen the existing one.
     *
     * @param array<string,mixed> $evidence
     * @return string 'raised' | 'updated'
     */
    private function raise(
        string $dedupeKey,
        string $domainName,
        string $severity,
        string $category,
        string $title,
        string $description,
        string $recommendation,
        array $evidence,
    ): string {
        $existing = $this->mapper->findByDedupeKey($dedupeKey);
        $now = time();
        $evidenceJson = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($existing !== null) {
            $reopened = false;
            if ($existing->getStatus() === RepIncident::STATUS_RESOLVED) {
                $existing->setStatus(RepIncident::STATUS_OPEN);
                $existing->setResolvedAt(null);
                $existing->setResolvedBy(null);
                $existing->appendMeasure('system', 'reopened', $this->l10n->t('Condition detected again.'));
                $reopened = true;
            }
            $existing->setSeverity($severity);
            $existing->setTitle($title);
            $existing->setDescription($description);
            $existing->setRecommendation($recommendation);
            $existing->setEvidence($evidenceJson);
            $existing->setUpdatedAt($now);
            $this->mapper->update($existing);
            return $reopened ? 'raised' : 'updated';
        }

        $incident = new RepIncident();
        $incident->setDedupeKey($dedupeKey);
        $incident->setDomainName($domainName);
        $incident->setSeverity($severity);
        $incident->setCategory($category);
        $incident->setTitle($title);
        $incident->setDescription($description);
        $incident->setRecommendation($recommendation);
        $incident->setEvidence($evidenceJson);
        $incident->setStatus(RepIncident::STATUS_OPEN);
        $incident->setCreatedAt($now);
        $incident->setUpdatedAt($now);
        $incident->appendMeasure('system', 'detected', $this->l10n->t('Automatically detected by the reputation analysis.'));
        $this->mapper->insert($incident);
        $this->logger->info('Reputation incident raised', [
            'app' => Application::APP_ID, 'key' => $dedupeKey, 'severity' => $severity,
        ]);
        return 'raised';
    }

    /**
     * @param string[] $seenKeys
     */
    private function autoResolveMissing(string $domainName, array $seenKeys): int {
        $resolved = 0;
        foreach ($this->mapper->findOpen($domainName) as $incident) {
            if (in_array($incident->getDedupeKey(), $seenKeys, true)) {
                continue;
            }
            $incident->setStatus(RepIncident::STATUS_RESOLVED);
            $incident->setResolvedAt(time());
            $incident->setResolvedBy('system');
            $incident->setUpdatedAt(time());
            $incident->appendMeasure('system', 'auto_resolved', $this->l10n->t(
                'Condition was no longer detected in the latest analysis.'
            ));
            $this->mapper->update($incident);
            $resolved++;
        }
        return $resolved;
    }

    /** @param array<string,mixed> $check */
    private function describeCheckFailure(string $id, array $check): string {
        $observed = $check['observed'] ?? [];
        $target = (string)($observed['target'] ?? '?');
        $unknown = $this->l10n->t('unknown');
        switch ($id) {
            case 'blacklist_ip':
            case 'blacklist_domain':
                $names = array_map(
                    static fn(array $l) => $l['name'] . (($l['category'] ?? '') !== '' ? ' (' . $l['category'] . ')' : ''),
                    array_filter((array)($observed['listed'] ?? []), 'is_array'),
                );
                $listedCount = (int)($observed['listedCount'] ?? count($names));
                $totalChecked = (int)($observed['totalChecked'] ?? 0);
                $listing = implode(', ', $names);
                return $id === 'blacklist_ip'
                    ? $this->l10n->t(
                        'The outbound IP %1$s is listed on %2$d of %3$d checked blacklists: %4$s. '
                        . 'Listed senders are rejected or marked as spam by many recipients.',
                        [$target, $listedCount, $totalChecked, $listing],
                    )
                    : $this->l10n->t(
                        'The domain %1$s is listed on %2$d of %3$d checked blacklists: %4$s. '
                        . 'Listed senders are rejected or marked as spam by many recipients.',
                        [$target, $listedCount, $totalChecked, $listing],
                    );
            case 'spf_record':
                return $this->l10n->t(
                    'No valid SPF record (v=spf1 …) was found for the domain. Without SPF, receivers '
                    . 'cannot verify which servers are allowed to send in the name of the domain.'
                );
            case 'dmarc_policy':
                return $this->l10n->t(
                    'No DMARC record (_dmarc.<domain>) was found for the domain. Without DMARC anyone '
                    . 'can spoof the domain, and there are no reports about abuse.'
                );
            case 'mta_sts':
                return $this->l10n->t(
                    'The MTA-STS DNS record exists but the policy file is not reachable over HTTPS. '
                    . 'Sending servers cannot apply the TLS policy as a result.'
                );
            case 'ptr':
                return $this->l10n->t(
                    'No PTR/reverse-DNS record exists for the outbound IP %s. Many receivers (e.g. GMX, '
                    . 'T-Online) reject mail from IPs without PTR.',
                    [(string)($observed['ip'] ?? '?')],
                );
            case 'helo_tls':
                return $this->l10n->t(
                    'The mail server does not offer STARTTLS – mail would be transferred unencrypted '
                    . 'and rejected by recipients that require TLS.'
                );
            case 'dkim':
                return $this->l10n->t(
                    'The DKIM signature of the latest mail test was invalid (result: %s). Invalidly '
                    . 'signed mail loses DMARC protection and reputation.',
                    [(string)($observed['result'] ?? '?')],
                );
            case 'spf_alignment':
                return $this->l10n->t(
                    'SPF does not pass or is not aligned with the customer domain (SPF domain: %s). '
                    . 'Only an aligned SPF pass counts for DMARC.',
                    [(string)($observed['spf_domain'] ?? $unknown)],
                );
            case 'dkim_alignment':
                return $this->l10n->t(
                    'DKIM does not pass or signs with a third-party domain (%s). Only an aligned DKIM '
                    . 'signature counts for DMARC.',
                    [(string)($observed['dkim_domain'] ?? $unknown)],
                );
            default:
                return $this->l10n->t(
                    'The check "%1$s" failed. Observed values: %2$s',
                    [$id, (string)json_encode($observed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                );
        }
    }
}
