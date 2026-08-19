<?php
declare(strict_types=1);

namespace OCA\SouveraShield\BackgroundJob;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Db\LoginTrace;
use OCA\SouveraShield\Db\LoginBaseline;
use OCA\SouveraShield\Db\LoginTraceMapper;
use OCA\SouveraShield\Db\LoginBaselineMapper;
use OCA\SouveraShield\Db\SuspiciousEvent;
use OCA\SouveraShield\Db\SuspiciousEventMapper;
use OCA\SouveraShield\Db\LoginFeedbackMapper;
use OCA\SouveraShield\Service\IpEnrichmentService;
use OCA\SouveraShield\Service\SuspiciousLoginRules;
use OCA\SouveraShield\Service\CentralSettings;
use OCA\SouveraShield\Notification\SuspiciousLoginNotifier;
use OCP\BackgroundJob\QueuedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Runs every 2 minutes, finds unscored login traces, enriches IPs and scores
 * them via SuspiciousLoginRules. If score >= 20, creates a SuspiciousEvent
 * and sends a notification.
 */
class ScoreLoginTracesJob extends QueuedJob {

    public function __construct(
        ITimeFactory $time,
    ) {
        parent::__construct($time);
    }

    /**
     * @param mixed $argument
     */
    protected function run($argument): void {
        $traceMapper = \OCP\Server::get(LoginTraceMapper::class);
        $baselineMapper = \OCP\Server::get(LoginBaselineMapper::class);
        $eventMapper = \OCP\Server::get(SuspiciousEventMapper::class);
        $feedbackMapper = \OCP\Server::get(LoginFeedbackMapper::class);
        $ipEnrichment = \OCP\Server::get(IpEnrichmentService::class);
        $rules = \OCP\Server::get(SuspiciousLoginRules::class);
        $central = \OCP\Server::get(CentralSettings::class);
        $notifier = \OCP\Server::get(SuspiciousLoginNotifier::class);
        $logger = \OCP\Server::get(LoggerInterface::class);

        $traces = $traceMapper->findUnscored(50);

        foreach ($traces as $trace) {
            try {
                $this->processTrace($trace, $traceMapper, $baselineMapper, $eventMapper,
                    $feedbackMapper, $ipEnrichment, $rules, $central, $notifier, $logger);
            } catch (\Throwable $e) {
                $logger->error('Failed to score login trace', [
                    'app'       => Application::APP_ID,
                    'trace_id'  => $trace->getId(),
                    'exception' => $e,
                ]);
            }
        }
    }

    private function processTrace(
        LoginTrace $trace,
        LoginTraceMapper $traceMapper,
        LoginBaselineMapper $baselineMapper,
        SuspiciousEventMapper $eventMapper,
        LoginFeedbackMapper $feedbackMapper,
        IpEnrichmentService $ipEnrichment,
        SuspiciousLoginRules $rules,
        CentralSettings $central,
        SuspiciousLoginNotifier $notifier,
        LoggerInterface $logger,
    ): void {
        $ip = $trace->getIp();
        if ($ip === null || $ip === '') {
            // Mark as scored so findUnscored() does not re-process it forever.
            $trace->setRiskScore(0);
            try {
                $traceMapper->update($trace);
            } catch (\Throwable $e) {
                $logger->debug('Failed to mark empty-IP trace as scored', [
                    'app'       => Application::APP_ID,
                    'trace_id'  => $trace->getId(),
                    'exception' => $e,
                ]);
            }
            return;
        }

        $enrichment = null;
        try {
            $enrichment = $ipEnrichment->enrich($ip);
        } catch (\Throwable $e) {
            $logger->debug('IP enrichment failed for trace', [
                'app'       => Application::APP_ID,
                'trace_id'  => $trace->getId(),
                'ip'        => $ip,
                'exception' => $e,
            ]);
        }

        // Update trace with enrichment data
        if ($enrichment !== null) {
            $trace->setGeoCountry($enrichment['countryCode'] ?? $enrichment['country'] ?? null);
            $trace->setGeoCity($enrichment['city'] ?? null);
            $trace->setIspName($enrichment['isp'] ?? null);
            $trace->setAsn($enrichment['asn'] ?? null);

            // Collect risk flags from enrichment
            $flags = [];
            if (!empty($enrichment['hosting'])) {
                $flags[] = 'hosting';
            }
            if (!empty($enrichment['vpn'])) {
                $flags[] = 'vpn';
            }
            if (!empty($enrichment['proxy'])) {
                $flags[] = 'proxy';
            }
            if (!empty($enrichment['tor'])) {
                $flags[] = 'tor';
            }
            if (!empty($enrichment['blocklists'])) {
                $flags[] = 'blocklisted';
            }
            $trace->setRiskFlags(!empty($flags) ? json_encode($flags) : null);
        }

        // Load baseline
        $baseline = null;
        $baselineArr = null;
        try {
            $baseline = $baselineMapper->find($trace->getUserId());
            $baselineArr = $this->baselineToArray($baseline);
        } catch (\Throwable) {
            // No baseline yet — all rules will fire at full strength
        }

        // Load feedback for this user+subnet
        $feedbackArr = null;
        $subnet = $trace->getIpSubnet();
        if ($subnet !== null) {
            $feedbacks = $feedbackMapper->findByUserAndSubnet($trace->getUserId(), $subnet);
            if (!empty($feedbacks)) {
                $feedbackArr = [];
                foreach ($feedbacks as $fb) {
                    $feedbackArr[] = [
                        'feedback' => $fb->getFeedback(),
                    ];
                }
            }
        }

        // Load recent traces for spike/pattern detection
        $prevCutoff = time() - 3600; // 1 hour window
        $previousTraces = $traceMapper->findTracesSince($trace->getUserId(), $prevCutoff);
        $prevArr = array_map(fn(LoginTrace $t) => [
            'created_at' => $t->getCreatedAt(),
            'success'    => $t->getSuccess(),
        ], $previousTraces);

        // Build trace array for scoring
        $traceArr = [
            'geo_country'  => $trace->getGeoCountry(),
            'isp_name'     => $trace->getIspName(),
            'ip_subnet'    => $trace->getIpSubnet(),
            'device_hash'  => $trace->getDeviceHash(),
            'created_at'   => $trace->getCreatedAt(),
            'success'      => $trace->getSuccess(),
        ];

        // Apply rules
        $result = $rules->score($traceArr, $baselineArr, $enrichment, $feedbackArr, $prevArr);

        // Update trace with score
        $trace->setRiskScore($result['score']);
        $trace->setRuleResults(json_encode($result['rules']));
        $traceMapper->update($trace);

        // Feature switch: when detection is disabled, score traces for
        // transparency but do NOT create events or send notifications.
        $detectionEnabled = $central->suspiciousLoginDetectionEnabled();
        $threshold = $detectionEnabled ? $central->suspiciousLoginScoreThreshold() : 20;

        // Incrementally update the baseline so subsequent logins from the
        // same country/ISP/device don't trigger `new_country` etc. again
        // before the nightly UpdateBaselinesJob reruns.
        //
        // Only UNSUSPICIOUS logins may feed the baseline: adding every login
        // (including flagged ones) would let an attacker poison the trusted
        // lists — after a few logins the attacker's subnet/country would be
        // "trusted" and push the legitimate workplace out of the top-5.
        if ($detectionEnabled && $result['score'] < $threshold) {
            $this->updateBaselineIncrementally(
                $trace, $baseline, $baselineMapper, $logger
            );
        }

        // Create suspicious event if score meets threshold
        if ($detectionEnabled && $result['score'] >= $threshold) {
            $event = new SuspiciousEvent();
            $event->setUserId($trace->getUserId());
            $event->setTraceId($trace->getId());
            $event->setConfidence($result['score']);
            $event->setSeverity($result['severity']);
            $event->setDecision($result['decision']);
            $event->setIp($trace->getIp());
            $event->setGeoCountry($trace->getGeoCountry());
            $event->setGeoCity($trace->getGeoCity());
            $event->setIspName($trace->getIspName());
            $event->setRiskFlags(json_encode($result['rules']));
            $event->setResolved(0);
            $event->setCreatedAt(time());

            $eventMapper->insert($event);

            // Send notification for high/critical events
            if (in_array($result['severity'], ['high', 'critical'], true)) {
                try {
                    $notifier->notify($event, $trace->getUserId());
                } catch (\Throwable $e) {
                    $logger->warning('Failed to send suspicious login notification', [
                        'app'       => Application::APP_ID,
                        'event_id'  => $event->getId(),
                        'exception' => $e,
                    ]);
                }
            }
        }
    }

    /**
     * Incrementally update the baseline after scoring a trace so the next
     * login from the same country/ISP/subnet won't fire `new_country` again.
     */
    private function updateBaselineIncrementally(
        LoginTrace $trace,
        ?LoginBaseline $baseline,
        LoginBaselineMapper $baselineMapper,
        LoggerInterface $logger,
    ): void {
        try {
            $userId = $trace->getUserId();
            $now = time();
            $country = $trace->getGeoCountry();
            $subnet = $trace->getIpSubnet();
            $isp = $trace->getIspName();
            $device = $trace->getDeviceHash();
            $createdAt = $trace->getCreatedAt();

            if ($baseline === null) {
                $baseline = new LoginBaseline();
                $baseline->setUserId($userId);
                $baseline->setTotalLogins(0);
                $baseline->setActiveDays(0);
                $baseline->setFirstSeen($now);
                $baseline->setLastSeen(0);
                $baseline->setTrustedSubnets('[]');
                $baseline->setTrustedCountries('[]');
                $baseline->setTrustedIsps('[]');
                $baseline->setTrustedDevices('[]');
                $baseline->setTypicalHours('[]');
                $baseline->setAvgLoginsPerDay(0.0);
                $baseline->setGracePeriodUntil(-1);
            }

            $baseline->setTotalLogins($baseline->getTotalLogins() + 1);
            $baseline->setLastSeen(max($baseline->getLastSeen() ?? 0, $createdAt));

            $helper = function (?string $json, ?string $newValue, int $max = 5): string {
                $values = $json ? json_decode($json, true) : [];
                if (!is_array($values)) { $values = []; }
                if ($newValue !== null && $newValue !== '') {
                    $values = array_filter($values, fn($v) => $v !== $newValue);
                    array_unshift($values, $newValue);
                    $values = array_slice($values, 0, $max);
                }
                return json_encode(array_values($values));
            };

            if ($country !== null && $country !== '') {
                $baseline->setTrustedCountries(
                    $helper($baseline->getTrustedCountries(), $country)
                );
            }
            if ($subnet !== null && $subnet !== '') {
                $baseline->setTrustedSubnets(
                    $helper($baseline->getTrustedSubnets(), $subnet)
                );
            }
            if ($isp !== null && $isp !== '') {
                $baseline->setTrustedIsps(
                    $helper($baseline->getTrustedIsps(), $isp)
                );
            }
            if ($device !== null && $device !== '') {
                $baseline->setTrustedDevices(
                    $helper($baseline->getTrustedDevices(), $device)
                );
            }

            $baselineMapper->insertIfNew($baseline);
            // If it already existed, insertIfNew returned the existing row;
            // we need to update to persist the new values.
            if ($baseline->getTotalLogins() > 1) {
                $baselineMapper->update($baseline);
            }
        } catch (\Throwable $e) {
            $logger->debug('Failed to update baseline incrementally', [
                'app'       => Application::APP_ID,
                'user_id'   => $trace->getUserId(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function baselineToArray(?LoginBaseline $baseline): ?array {
        if ($baseline === null) {
            return null;
        }
        $decode = function (?string $json): ?array {
            if ($json === null || $json === '') {
                return null;
            }
            $decoded = json_decode($json, true);
            return is_array($decoded) ? $decoded : null;
        };
        return [
            'total_logins'       => $baseline->getTotalLogins(),
            'active_days'        => $baseline->getActiveDays(),
            'first_seen'         => $baseline->getFirstSeen(),
            'last_seen'          => $baseline->getLastSeen(),
            'trusted_subnets'    => $decode($baseline->getTrustedSubnets()),
            'trusted_countries'  => $decode($baseline->getTrustedCountries()),
            'trusted_isps'       => $decode($baseline->getTrustedIsps()),
            'trusted_devices'    => $decode($baseline->getTrustedDevices()),
            'typical_hours'      => $decode($baseline->getTypicalHours()),
            'avg_logins_per_day' => $baseline->getAvgLoginsPerDay(),
            'grace_period_until' => $baseline->getGracePeriodUntil(),
        ];
    }
}
