<?php
declare(strict_types=1);

namespace OCA\SouveraShield\BackgroundJob;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Db\MailTest;
use OCA\SouveraShield\Service\MailTestService;
use OCA\SouveraShield\Service\ManagedDomainService;
use OCA\SouveraShield\Service\ProviderToolsClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Runs one mail-test against the workspace's single managed domain every
 * MONDAY at a random, instance-specific time between 00:01 and 06:00
 * (local server time).
 *
 * Design:
 *   - Interval = 15 min so the random Monday-morning slot is hit closely;
 *     the guards inside run() prevent duplicate work.
 *   - The slot is drawn once via random_int() and persisted in the app
 *     config, so the whole Souvera fleet spreads its weekly tests instead
 *     of firing them all at the same moment.
 *   - An ISO-week marker guarantees exactly one run per week even if the
 *     job fires multiple times after the slot.
 */
class WeeklyMailTestJob extends TimedJob {

    private const MARKER_KEY = 'weekly_mail_test.last_run_week';
    private const SLOT_KEY   = 'weekly_mail_test.slot_seconds';

    /** Dispatch window on Monday: 00:01:00 – 06:00:00. */
    private const SLOT_MIN_SECONDS = 60;
    private const SLOT_MAX_SECONDS = 6 * 3600;

    public function __construct(
        ITimeFactory $time,
        private readonly ManagedDomainService $managed,
        private readonly MailTestService $mailTestService,
        private readonly ProviderToolsClient $provider,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(15 * 60);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    /**
     * @param mixed $argument
     */
    protected function run($argument): void {
        if (!$this->provider->isConfigured()) {
            return;
        }

        $now = $this->time->getTime();
        if ((int)date('N', $now) !== 1) {
            return; // Monday only
        }

        $secondsSinceMidnight = $now - (int)strtotime('today', $now);
        if ($secondsSinceMidnight < $this->slotSeconds()) {
            return; // instance-specific slot not reached yet
        }

        $weekKey = date('o-\WW', $now); // e.g. "2026-W25"
        $lastRun = $this->appConfig->getValueString(Application::APP_ID, self::MARKER_KEY, '', lazy: true);
        if ($lastRun === $weekKey) {
            return; // already ran this ISO week
        }

        $domain = $this->managed->getOrCreate();
        if ($domain === null) {
            // Workspace not fully provisioned yet – no domain to test.
            return;
        }

        $this->appConfig->setValueString(Application::APP_ID, self::MARKER_KEY, $weekKey, lazy: true);

        try {
            $this->mailTestService->run(
                $domain,
                MailTest::TRIGGER_WEEKLY,
                null,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Weekly mail-test failed', [
                'app' => Application::APP_ID,
                'domain' => $domain->getDomain(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Random per-instance dispatch slot (seconds after midnight),
     * drawn once and persisted so it stays stable for this deployment.
     */
    private function slotSeconds(): int {
        $stored = (int)$this->appConfig->getValueString(Application::APP_ID, self::SLOT_KEY, '0', lazy: true);
        if ($stored >= self::SLOT_MIN_SECONDS && $stored <= self::SLOT_MAX_SECONDS) {
            return $stored;
        }
        $slot = random_int(self::SLOT_MIN_SECONDS, self::SLOT_MAX_SECONDS);
        $this->appConfig->setValueString(Application::APP_ID, self::SLOT_KEY, (string)$slot, lazy: true);
        $this->logger->info('Weekly mail-test slot initialised: Mondays at ' . gmdate('H:i:s', $slot), [
            'app' => Application::APP_ID,
        ]);
        return $slot;
    }
}
