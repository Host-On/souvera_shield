<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Controller;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Db\DmarcDomain;
use OCA\SouveraShield\Db\RepIncident;
use OCA\SouveraShield\Service\ManagedDomainService;
use OCA\SouveraShield\Service\ProviderToolsClient;
use OCA\SouveraShield\Service\ProviderToolsException;
use OCA\SouveraShield\Service\Reputation\AnalysisRunner;
use OCA\SouveraShield\Service\Reputation\DeliverabilityCheckService;
use OCA\SouveraShield\Service\Reputation\DmarcInsightService;
use OCA\SouveraShield\Service\Reputation\IncidentService;
use OCA\SouveraShield\Service\Reputation\ReputationScoreService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * REST endpoints for the extended reputation analysis:
 * composite score, provider-specific reputation, deliverability checks,
 * DMARC source classification and reputation incidents.
 *
 * All routes are protected by
 * {@see \OCA\SouveraShield\Middleware\AdminAccessMiddleware}
 * (souvera-admins group), identical to the DmarcController.
 */
class ReputationController extends Controller {

    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly ManagedDomainService $managed,
        private readonly DeliverabilityCheckService $checkService,
        private readonly DmarcInsightService $insightService,
        private readonly ReputationScoreService $scoreService,
        private readonly IncidentService $incidentService,
        private readonly AnalysisRunner $analysisRunner,
        private readonly ProviderToolsClient $providerTools,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Composite score + component breakdown + score history + forensic
     * (RUF) feedback summary.
     */
    #[NoAdminRequired]
    public function overview(): JSONResponse {
        $domain = $this->requireDomain();
        if ($domain instanceof JSONResponse) {
            return $domain;
        }
        $days = $this->days();

        $checks = $this->safeChecks($domain, false);
        $stats  = $this->safeStats($domain, $days);

        $result = $this->scoreService->compute($domain, $stats, $checks);
        try {
            $this->scoreService->snapshot($domain->getDomain(), $result);
        } catch (\Throwable $e) {
            $this->logger->warning('Score snapshot failed', ['app' => Application::APP_ID, 'exception' => $e]);
        }

        return new JSONResponse(['data' => [
            'score'        => $result['score'],
            'state'        => $result['state'],
            'components'   => $result['components'],
            'history'      => $this->scoreService->history($domain->getDomain()),
            'forensic'     => $this->forensicSummary($domain, $checks),
            'days'         => $days,
            'generated_at' => time(),
        ]]);
    }

    /** Provider-specific reputation (Google, Microsoft, Yahoo, GMX/Web.de). */
    #[NoAdminRequired]
    public function providers(): JSONResponse {
        $domain = $this->requireRegisteredDomain();
        if ($domain instanceof JSONResponse) {
            return $domain;
        }
        $days = $this->days();
        $insights = $this->insightService->collect((string)$domain->getProviderDomainId(), $days);
        return new JSONResponse(['data' => [
            'providers'        => $insights['providers'],
            'reports_analyzed' => $insights['reports_analyzed'],
            'days'             => $days,
        ]]);
    }

    /** Extended deliverability checks (`?refresh=1` bypasses the cache). */
    #[NoAdminRequired]
    public function checks(): JSONResponse {
        $domain = $this->requireDomain();
        if ($domain instanceof JSONResponse) {
            return $domain;
        }
        $refresh = (string)$this->request->getParam('refresh', '') === '1';
        return new JSONResponse(['data' => $this->checkService->getChecks($domain, $refresh)]);
    }

    /** DMARC sending sources classified legitimate / unknown / abusive. */
    #[NoAdminRequired]
    public function sources(): JSONResponse {
        $domain = $this->requireRegisteredDomain();
        if ($domain instanceof JSONResponse) {
            return $domain;
        }
        $days = $this->days();
        $insights = $this->insightService->collect((string)$domain->getProviderDomainId(), $days);
        return new JSONResponse(['data' => [
            'sources'   => $insights['sources'],
            'anomalies' => $insights['anomalies'],
            'days'      => $days,
        ]]);
    }

    /** Incident list (`?status=open|resolved|all`, default all). */
    #[NoAdminRequired]
    public function incidents(): JSONResponse {
        $domain = $this->requireDomain();
        if ($domain instanceof JSONResponse) {
            return $domain;
        }
        $status = (string)$this->request->getParam('status', 'all');
        $rows = $this->incidentService->listIncidents($domain->getDomain(), $status);
        return new JSONResponse(['data' => [
            'incidents' => array_map(fn(RepIncident $i) => $this->hydrateIncident($i), $rows),
            'last_analysis_at' => $this->analysisRunner->lastRunAt(),
        ]]);
    }

    /** Mark a single incident as manually resolved. */
    #[NoAdminRequired]
    public function resolveIncident(int $incidentId): JSONResponse {
        try {
            $incident = $this->incidentService->resolve($incidentId, $this->currentUid());
        } catch (DoesNotExistException) {
            return $this->fail('Incident not found.', Http::STATUS_NOT_FOUND);
        }
        return new JSONResponse(['data' => $this->hydrateIncident($incident)]);
    }

    /**
     * Full on-demand analysis run: refresh checks, recompute score,
     * snapshot, run incident detection. Same work the daily background
     * job performs ({@see AnalysisRunner}).
     */
    #[NoAdminRequired]
    public function analyze(): JSONResponse {
        $domain = $this->requireDomain();
        if ($domain instanceof JSONResponse) {
            return $domain;
        }
        return new JSONResponse(['data' => $this->analysisRunner->run($domain, true, $this->days())]);
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    private function requireDomain(): DmarcDomain|JSONResponse {
        $entity = $this->managed->getOrCreate();
        if ($entity === null) {
            return $this->fail(
                'No mail domain is configured for this workspace yet. '
                . 'Ask your hoster to configure the PMG allowed domains.',
                Http::STATUS_FAILED_DEPENDENCY,
            );
        }
        return $entity;
    }

    private function requireRegisteredDomain(): DmarcDomain|JSONResponse {
        $entity = $this->requireDomain();
        if ($entity instanceof JSONResponse) {
            return $entity;
        }
        if ((string)$entity->getProviderDomainId() === '') {
            return $this->fail(
                'Domain must be registered first.',
                Http::STATUS_PRECONDITION_FAILED,
            );
        }
        return $entity;
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

    /**
     * Forensic (RUF) feedback summary – the honest complaint/feedback-loop
     * data we actually have. Provider portals (Google Postmaster, MS SNDS)
     * have no public API; the frontend explains how to register there.
     *
     * @param array<string,mixed> $checks
     * @return array<string,mixed>
     */
    private function forensicSummary(DmarcDomain $domain, array $checks): array {
        $rufConfigured = false;
        foreach (($checks['checks'] ?? []) as $check) {
            if (($check['id'] ?? '') === 'dmarc_policy') {
                $rufConfigured = !empty($check['observed']['ruf']);
                break;
            }
        }
        $reports = null;
        if ((string)$domain->getProviderDomainId() !== '') {
            try {
                $details = $this->providerTools->getDomainDetails((string)$domain->getProviderDomainId());
                if (array_key_exists('totalForensicReports', $details)) {
                    $reports = (int)$details['totalForensicReports'];
                }
            } catch (ProviderToolsException $e) {
                $this->logger->debug('Domain details unavailable', ['app' => Application::APP_ID, 'error' => $e->getMessage()]);
            }
        }
        return [
            'available'      => $reports !== null,
            'reports'        => $reports,
            'ruf_configured' => $rufConfigured,
        ];
    }

    /** @return array<string,mixed> */
    private function hydrateIncident(RepIncident $i): array {
        $evidence = json_decode((string)($i->getEvidence() ?? 'null'), true);
        return [
            'id'             => (int)$i->getId(),
            'severity'       => $i->getSeverity(),
            'category'       => $i->getCategory(),
            'title'          => $i->getTitle(),
            'description'    => $i->getDescription(),
            'recommendation' => $i->getRecommendation(),
            'domain'         => $i->getDomainName(),
            'status'         => $i->getStatus(),
            'evidence'       => is_array($evidence) ? $evidence : null,
            'measures'       => $i->measuresList(),
            'created_at'     => (int)$i->getCreatedAt(),
            'updated_at'     => (int)$i->getUpdatedAt(),
            'resolved_at'    => $i->getResolvedAt(),
            'resolved_by'    => $i->getResolvedBy(),
        ];
    }

    private function days(): int {
        $days = (int)$this->request->getParam('days', 30);
        return max(1, min(365, $days));
    }

    private function fail(string $msg, int $status): JSONResponse {
        return new JSONResponse(['error' => $msg], $status);
    }

    private function currentUid(): string {
        $u = $this->userSession->getUser();
        return $u !== null ? $u->getUID() : 'system';
    }
}
