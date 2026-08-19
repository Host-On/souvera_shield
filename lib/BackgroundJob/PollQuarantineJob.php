<?php
declare(strict_types=1);

namespace OCA\SouveraShield\BackgroundJob;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Service\CentralSettings;
use OCA\SouveraShield\Service\PMGClient;
use OCA\SouveraShield\Service\PMGException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Poll PMG every 10 minutes and raise a notification per user whose
 * quarantine has grown since the last check.
 *
 * Global toggles live in Souvera Central:
 *   - settings.shield.desktop_notifications  ← master on/off switch
 *   - settings.shield.min_spam_score         ← below this score → ignore
 *
 * Per-user state (last seen count) is kept in the user_value table of the
 * souvera_shield app – this is operational state, not user preference.
 */
class PollQuarantineJob extends TimedJob {

    /**
     * Retry cadence when PMG is temporarily unavailable (5xx / connection
     * errors). Sleeps 200 ms → 1 s → 5 s, matching what a "PMG restart"
     * typically needs. All values are in microseconds.
     */
    private const RETRY_BACKOFFS_US = [200_000, 1_000_000, 5_000_000];

    public function __construct(
        ITimeFactory $time,
        private readonly IUserManager $userManager,
        private readonly IConfig $config,
        private readonly INotificationManager $notifications,
        private readonly PMGClient $pmg,
        private readonly CentralSettings $central,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(10 * 60); // every 10 minutes
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    /**
     * @param mixed $argument
     */
    protected function run($argument): void {
        if (!$this->central->desktopNotificationsEnabled()) {
            return;
        }
        $minScore = $this->central->minSpamScore();

        $this->userManager->callForSeenUsers(function (IUser $user) use ($minScore): void {
            $this->processUser($user, $minScore);
        });
    }

    private function processUser(IUser $user, float $minScore): void {
        $email = $user->getEMailAddress();
        if ($email === null || $email === '') {
            return;
        }
        if (!$this->pmg->isAllowedDomain($email)) {
            return;
        }

        $res = $this->fetchQuarantineWithRetry($user, $email);
        if ($res === null) {
            return;
        }

        // Apply the global min-spam-score filter from Central
        $rows = array_filter($res['data'], static function (array $row) use ($minScore): bool {
            $score = (float)($row['spamlevel'] ?? $row['spam'] ?? $row['score'] ?? 0);
            return $score >= $minScore;
        });
        $count = count($rows);

        $lastSeen = (int)$this->config->getUserValue($user->getUID(), Application::APP_ID, 'last_seen_count', '0');
        $this->config->setUserValue($user->getUID(), Application::APP_ID, 'last_seen_count', (string)$count);

        if ($count > $lastSeen) {
            $new = $count - $lastSeen;
            $notification = $this->notifications->createNotification();
            $notification
                ->setApp(Application::APP_ID)
                ->setUser($user->getUID())
                ->setDateTime(new \DateTime())
                ->setObject('quarantine', (string)$count)
                ->setSubject('new_quarantine', ['count' => $new]);
            $this->notifications->notify($notification);
        }
    }

    /**
     * Ask PMG for the user's spam quarantine, retrying transparently on
     * transient failures (5xx / connection errors, HTTP 0 or ≥500). Auth
     * or permission errors (401/403/404) are not retried – re-hammering
     * PMG will just yield the same answer.
     *
     * Returns `null` if all retries were exhausted (call is skipped for
     * this cycle – the 10-minute TimedJob will try again).
     */
    private function fetchQuarantineWithRetry(IUser $user, string $email): ?array {
        $attempts = count(self::RETRY_BACKOFFS_US) + 1;
        $lastException = null;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $this->pmg->getSpamQuarantine($email);
            } catch (PMGException $e) {
                $lastException = $e;

                if (!$this->isRetryable($e) || $i === $attempts - 1) {
                    break;
                }

                $sleepUs = self::RETRY_BACKOFFS_US[$i];
                $this->logger->debug('PMG poll transient failure; retrying', [
                    'user'      => $user->getUID(),
                    'attempt'   => $i + 1,
                    'sleep_ms'  => intdiv($sleepUs, 1000),
                    'status'    => $e->getHttpStatus(),
                    'error'     => $e->getMessage(),
                ]);
                usleep($sleepUs);
            }
        }

        $this->logger->debug('Poll skipped after retries: ' . $lastException?->getMessage(), [
            'user' => $user->getUID(),
        ]);
        return null;
    }

    /**
     * A PMGException is worth retrying only for transport-level or 5xx
     * failures. Client errors (401/403/404) or `assertSuccess()` failures
     * signal a permanent problem for this cycle.
     */
    private function isRetryable(PMGException $e): bool {
        $status = $e->getHttpStatus();
        return $status === 0 || $status >= 500;
    }
}
