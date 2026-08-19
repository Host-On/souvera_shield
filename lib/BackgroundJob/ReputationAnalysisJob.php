<?php
declare(strict_types=1);

namespace OCA\SouveraShield\BackgroundJob;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Service\ManagedDomainService;
use OCA\SouveraShield\Service\Reputation\AnalysisRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily reputation analysis – delegates the actual work to
 * {@see AnalysisRunner} (checks refresh, incident detection, score
 * snapshot), which is also used by the REST endpoint and the automatic
 * detection after every finished mail-test.
 */
class ReputationAnalysisJob extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private readonly ManagedDomainService $managed,
        private readonly AnalysisRunner $analysisRunner,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 3600);
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        try {
            $domain = $this->managed->getOrCreate();
        } catch (\Throwable $e) {
            $this->logger->warning('Reputation analysis skipped – managed domain unavailable', [
                'app' => Application::APP_ID, 'exception' => $e,
            ]);
            return;
        }
        if ($domain === null) {
            return;
        }

        try {
            $result = $this->analysisRunner->run($domain, true, 30);
            $this->logger->info('Reputation analysis completed', [
                'app'       => Application::APP_ID,
                'domain'    => $domain->getDomain(),
                'score'     => $result['score'],
                'incidents' => $result['incidents'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Reputation analysis failed', [
                'app' => Application::APP_ID, 'exception' => $e,
            ]);
        }
    }
}
