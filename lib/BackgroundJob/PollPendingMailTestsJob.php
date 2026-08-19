<?php
declare(strict_types=1);

namespace OCA\SouveraShield\BackgroundJob;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Db\MailTestMapper;
use OCA\SouveraShield\Service\MailTestService;
use OCA\SouveraShield\Service\ProviderToolsClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Polls provider.tools every 5 minutes for mail-tests that are still
 * pending / sent. Turns their status into `completed` or `error` as soon
 * as a definitive result arrives (typically 10-30 seconds after send).
 */
class PollPendingMailTestsJob extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private readonly MailTestMapper $tests,
        private readonly MailTestService $mailTestService,
        private readonly ProviderToolsClient $provider,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(5 * 60);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    /**
     * @param mixed $argument
     */
    protected function run($argument): void {
        if (!$this->provider->isConfigured()) {
            return;
        }
        $pending = $this->tests->findPending();
        foreach ($pending as $entity) {
            try {
                $this->mailTestService->refreshResult($entity);
            } catch (\Throwable $e) {
                $this->logger->warning('Polling mail-test result failed', [
                    'app' => Application::APP_ID,
                    'test_id' => $entity->getId(),
                    'exception' => $e,
                ]);
            }
        }
    }
}
