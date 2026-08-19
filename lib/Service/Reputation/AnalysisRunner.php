<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service\Reputation;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Db\DmarcDomain;
use OCA\SouveraShield\Db\MailTestMapper;
use OCA\SouveraShield\Service\ProviderToolsClient;
use OCA\SouveraShield\Service\ProviderToolsException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Single entry point for a full reputation analysis run:
 *   1. refresh the deliverability checks (DNS, SMTP probe, blacklists)
 *   2. collect DMARC insights and run the incident detection
 *   3. recompute the composite score and store a snapshot
 *
 * Shared by the daily background job, the "Run analysis now" endpoint
 * and the automatic detection after every finished mail-test – so
 * incidents (e.g. "DKIM-Signatur fehlgeschlagen") appear immediately
 * instead of up to 24 h later.
 */
class AnalysisRunner {

    private const LAST_RUN_KEY = 'reputation_last_analysis_at';

    public function __construct(
        private readonly DeliverabilityCheckService $checkService,
        private readonly DmarcInsightService $insightService,
        private readonly ReputationScoreService $scoreService,
        private readonly IncidentService $incidentService,
        private readonly MailTestMapper $tests,
        private readonly ProviderToolsClient $providerTools,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{score:mixed, state:mixed, incidents:array{raised:int, updated:int, auto_resolved:int, open:int}}
     */
    public function run(DmarcDomain $domain, bool $refreshChecks = true, int $days = 30): array {
        $checks = $this->safeChecks($domain, $refreshChecks);

        $insights = [];
        if ((string)$domain->getProviderDomainId() !== '') {
            try {
                $insights = $this->insightService->collect((string)$domain->getProviderDomainId(), $days);
            } catch (\Throwable $e) {
                $this->logger->warning('Insight collection failed during analysis', [
                    'app' => Application::APP_ID, 'exception' => $e,
                ]);
            }
        }
        $stats = $insights['stats'] ?? $this->safeStats($domain, $days);

        $recentTests = $this->tests->findRecent(20, (int)$domain->getId());
        $summary = $this->incidentService->runDetection($domain, $checks, $insights, $recentTests);

        $result = $this->scoreService->compute($domain, $stats, $checks);
        try {
            $this->scoreService->snapshot($domain->getDomain(), $result);
        } catch (\Throwable $e) {
            $this->logger->warning('Score snapshot failed', ['app' => Application::APP_ID, 'exception' => $e]);
        }

        $this->appConfig->setValueString(Application::APP_ID, self::LAST_RUN_KEY, (string)time(), lazy: true);

        return [
            'score'     => $result['score'],
            'state'     => $result['state'],
            'incidents' => $summary,
        ];
    }

    /** Unix timestamp of the latest completed analysis run, if any. */
    public function lastRunAt(): ?int {
        $ts = (int)$this->appConfig->getValueString(Application::APP_ID, self::LAST_RUN_KEY, '0', lazy: true);
        return $ts > 0 ? $ts : null;
    }

    /** @return array<string,mixed> */
    private function safeChecks(DmarcDomain $domain, bool $refresh): array {
        try {
            return $this->checkService->getChecks($domain, $refresh);
        } catch (\Throwable $e) {
            $this->logger->error('Deliverability checks failed', ['app' => Application::APP_ID, 'exception' => $e]);
            return ['generated_at' => time(), 'outbound_ip' => null, 'ip_source' => null, 'checks' => []];
        }
    }

    /** @return array<string,mixed> */
    private function safeStats(DmarcDomain $domain, int $days): array {
        if ((string)$domain->getProviderDomainId() === '') {
            return [];
        }
        try {
            return $this->providerTools->getDomainStats((string)$domain->getProviderDomainId(), $days);
        } catch (ProviderToolsException $e) {
            $this->logger->warning('Domain stats unavailable', ['app' => Application::APP_ID, 'error' => $e->getMessage()]);
            return [];
        }
    }
}
