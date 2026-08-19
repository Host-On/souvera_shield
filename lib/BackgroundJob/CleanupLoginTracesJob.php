<?php
declare(strict_types=1);

namespace OCA\SouveraShield\BackgroundJob;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Db\LoginTraceMapper;
use OCA\SouveraShield\Db\SuspiciousEventMapper;
use OCA\SouveraShield\Service\CentralSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily cleanup (runs at 04:00 UTC).
 *
 * Deletes:
 *   - Login traces older than retention_days (from CentralSettings)
 *   - Resolved suspicious events older than retention_resolved_days
 */
class CleanupLoginTracesJob extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private readonly LoginTraceMapper $traceMapper,
        private readonly SuspiciousEventMapper $eventMapper,
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
        $now = time();
        $retentionDays = $central->suspiciousLoginRetentionDays();
        $retentionResolvedDays = $central->suspiciousLoginRetentionResolvedDays();

        // Delete old traces
        $traceCutoff = $now - ($retentionDays * 86400);
        try {
            $deleted = $traceMapper->deleteOlderThan($traceCutoff);
            $logger->info('Cleaned up login traces', [
                'app'     => Application::APP_ID,
                'deleted' => $deleted,
                'cutoff'  => date('Y-m-d H:i:s', $traceCutoff),
            ]);
        } catch (\Throwable $e) {
            $logger->error('Failed to clean up login traces', [
                'app'       => Application::APP_ID,
                'exception' => $e,
            ]);
        }

        // Delete old resolved events
        $eventCutoff = $now - ($retentionResolvedDays * 86400);
        try {
            $deleted = $eventMapper->deleteOlderThan($eventCutoff, 1);
            $logger->info('Cleaned up resolved suspicious events', [
                'app'     => Application::APP_ID,
                'deleted' => $deleted,
                'cutoff'  => date('Y-m-d H:i:s', $eventCutoff),
            ]);
        } catch (\Throwable $e) {
            $logger->error('Failed to clean up resolved events', [
                'app'       => Application::APP_ID,
                'exception' => $e,
            ]);
        }
    }
}
