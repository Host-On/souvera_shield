<?php
declare(strict_types=1);

namespace OCA\SouveraShield\BackgroundJob;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Db\LoginTraceMapper;
use OCA\SouveraShield\Db\LoginBaseline;
use OCA\SouveraShield\Db\LoginBaselineMapper;
use OCA\SouveraShield\Service\SuspiciousLoginRules;
use OCA\SouveraShield\Service\CentralSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily baseline recalculation (runs at 03:00 UTC).
 *
 * For each user with login traces, recomputes:
 *   - trusted_subnets, trusted_countries, trusted_isps, trusted_devices
 *   - typical_hours, avg_logins_per_day
 *   - total_logins, active_days, first_seen, last_seen
 *
 * Respects the grace period from CentralSettings.
 */
class UpdateBaselinesJob extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private readonly LoginTraceMapper $traceMapper,
        private readonly LoginBaselineMapper $baselineMapper,
        private readonly SuspiciousLoginRules $rules,
        private readonly CentralSettings $central,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 3600);
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
    }

    /**
     * @param mixed $argument
     */
    protected function run($argument): void {
        $userIds = $traceMapper->distinctUserIds();

        foreach ($userIds as $userId) {
            try {
                $this->updateBaseline($userId);
            } catch (\Throwable $e) {
                $logger->error('Failed to update baseline for user', [
                    'app'       => Application::APP_ID,
                    'user_id'   => $userId,
                    'exception' => $e,
                ]);
            }
        }
    }

    private function updateBaseline(string $userId): void {
        $now = time();
        $gracePeriodDays = $central->suspiciousLoginGracePeriodDays();
        $graceCutoff = $now - ($gracePeriodDays * 86400);

        $traces = $traceMapper->findTracesSince($userId, $graceCutoff);
        if (empty($traces)) {
            return;
        }

        $traceArr = [];
        foreach ($traces as $t) {
            $traceArr[] = [
                'ip_subnet'   => $t->getIpSubnet(),
                'geo_country'  => $t->getGeoCountry(),
                'isp_name'     => $t->getIspName(),
                'device_hash'  => $t->getDeviceHash(),
                'created_at'   => $t->getCreatedAt(),
            ];
        }

        $totalLogins = count($traces);
        $firstSeen = $traceMapper->findFirstSeen($userId);
        $lastSeen = $traceMapper->findLastSeen($userId);
        $activeDays = $traceMapper->countActiveDays($userId);

        $firstTs = $firstSeen ?? $now;
        $daysSinceFirst = max(1, (int)(($now - $firstTs) / 86400));
        $avgLoginsPerDay = round($totalLogins / $daysSinceFirst, 2);

        $trustedSubnets = $rules->computeTrustedSubnets($traceArr);
        $trustedCountries = $rules->computeTrustedCountries($traceArr);
        $typicalHours = $rules->computeTypicalHours($traceArr);

        // Compute trusted ISPs from enriched traces
        $ispCounts = [];
        foreach ($traces as $t) {
            $isp = $t->getIspName();
            if ($isp !== null && $isp !== '') {
                $ispCounts[$isp] = ($ispCounts[$isp] ?? 0) + 1;
            }
        }
        arsort($ispCounts);
        $trustedIsps = array_keys(array_slice($ispCounts, 0, 5));

        // Compute trusted devices
        $deviceCounts = [];
        foreach ($traces as $t) {
            $dh = $t->getDeviceHash();
            if ($dh !== null && $dh !== '') {
                $deviceCounts[$dh] = ($deviceCounts[$dh] ?? 0) + 1;
            }
        }
        arsort($deviceCounts);
        $trustedDevices = array_keys(array_slice($deviceCounts, 0, 5));

        // Compute grace_period_until
        $gracePeriodUntil = $firstSeen ? ($firstSeen + ($gracePeriodDays * 86400)) : null;

        $isNew = false;
        try {
            $baseline = $baselineMapper->find($userId);
        } catch (\Throwable) {
            $isNew = true;
            $baseline = new LoginBaseline();
            $baseline->setUserId($userId);
        }

        $baseline->setTotalLogins($totalLogins);
        $baseline->setActiveDays($activeDays);
        $baseline->setFirstSeen($firstSeen);
        $baseline->setLastSeen($lastSeen);
        $baseline->setTrustedSubnets(json_encode($trustedSubnets));
        $baseline->setTrustedCountries(json_encode($trustedCountries));
        $baseline->setTrustedIsps(json_encode($trustedIsps));
        $baseline->setTrustedDevices(json_encode($trustedDevices));
        $baseline->setTypicalHours(json_encode($typicalHours));
        $baseline->setAvgLoginsPerDay($avgLoginsPerDay);
        $baseline->setGracePeriodUntil($gracePeriodUntil);

        if ($isNew) {
            $baselineMapper->insert($baseline);
        } else {
            $baselineMapper->update($baseline);
        }

        $logger->info('Baseline updated', [
            'app'       => Application::APP_ID,
            'user_id'   => $userId,
            'total'     => $totalLogins,
            'active_days' => $activeDays,
        ]);
    }
}
