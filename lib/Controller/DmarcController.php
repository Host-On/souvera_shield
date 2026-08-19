<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Controller;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Db\DmarcDomain;
use OCA\SouveraShield\Db\DmarcDomainMapper;
use OCA\SouveraShield\Db\MailTest;
use OCA\SouveraShield\Db\MailTestMapper;
use OCA\SouveraShield\Service\ManagedDomainService;
use OCA\SouveraShield\Service\MailTestService;
use OCA\SouveraShield\Service\ProviderToolsClient;
use OCA\SouveraShield\Service\ProviderToolsException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * REST endpoints for the Reputation Management area.
 *
 * There is exactly ONE managed domain per workspace, sourced from the
 * app configuration. Users cannot add or remove domains through the UI.
 *
 * The area is powered by the DMARC Analyzer of provider.tools
 * (`/dmarc/domains/*`), NOT by the one-shot `/dmarc-check` endpoint.
 * The full workflow is:
 *
 *   1. Register domain          POST /api/dmarc/domain/register
 *   2. Publish TXT + rua        (external / manual step)
 *   3. Verify ownership         POST /api/dmarc/domain/verify
 *   4. Read stats + reports     GET  /api/dmarc/domain/stats
 *                               GET  /api/dmarc/domain/reports
 *
 * All routes are protected by
 * {@see \OCA\SouveraShield\Middleware\AdminAccessMiddleware}
 * (souvera-admins group). This controller does no permission checks
 * of its own.
 */
class DmarcController extends Controller {

    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly ManagedDomainService $managed,
        private readonly DmarcDomainMapper $domains,
        private readonly MailTestMapper $tests,
        private readonly ProviderToolsClient $providerTools,
        private readonly MailTestService $mailTestService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    // -------------------------------------------------------------------
    // Status + domain payload
    // -------------------------------------------------------------------

    #[NoAdminRequired]
    public function status(): JSONResponse {
        return new JSONResponse([
            'configured'    => $this->providerTools->isConfigured(),
            'domain'        => $this->managed->getDomainName(),
            'sender_address'=> $this->managed->getSenderAddress(),
        ]);
    }

    /**
     * Full snapshot of the managed domain – used on page load and after
     * every mutation (register/verify).
     */
    #[NoAdminRequired]
    public function domain(): JSONResponse {
        $entity = $this->managed->getOrCreate();
        if ($entity === null) {
            return new JSONResponse([
                'error' => 'No mail domain is configured for this workspace yet. '
                         . 'Ask your hoster to configure the PMG allowed domains.',
            ], Http::STATUS_FAILED_DEPENDENCY);
        }
        $this->syncFromProvider($entity);
        return new JSONResponse(['data' => $this->hydrateDomain($entity)]);
    }

    // -------------------------------------------------------------------
    // DMARC Analyzer – register / verify / stats / reports
    // -------------------------------------------------------------------

    /**
     * Register the workspace domain with provider.tools's DMARC Analyzer.
     * Returns the setup instructions (verification TXT + rua DMARC
     * record) so the UI can display copy-ready values.
     */
    #[NoAdminRequired]
    public function register(): JSONResponse {
        $entity = $this->managed->getOrCreate();
        if ($entity === null) {
            return $this->fail('No managed domain configured.', Http::STATUS_FAILED_DEPENDENCY);
        }
        try {
            $data = $this->providerTools->registerDomain($entity->getDomain());
        } catch (ProviderToolsException $e) {
            $this->logger->warning('DMARC register failed', [
                'app'       => Application::APP_ID,
                'exception' => $e,
            ]);
            return $this->fail($e->getMessage(), Http::STATUS_BAD_GATEWAY);
        }

        $entity->setProviderDomainId((string)($data['id'] ?? ''));
        $entity->setVerificationTxt((string)($data['verificationTxt'] ?? '') ?: null);
        $entity->setReportEmail((string)($data['reportEmail'] ?? '') ?: null);
        $entity->setDmarcRecord((string)($data['dmarcRecord'] ?? '') ?: null);
        $entity->setProviderVerified(!empty($data['verified']) ? 1 : 0);
        $entity->setRegisteredAt(time());
        $entity = $this->domains->update($entity);

        return new JSONResponse(['data' => $this->hydrateDomain($entity)]);
    }

    /**
     * Verify domain ownership by asking provider.tools to look up the
     * `_provider-tools.<domain>` TXT record.
     */
    #[NoAdminRequired]
    public function verify(): JSONResponse {
        $entity = $this->managed->getOrCreate();
        if ($entity === null || (string)$entity->getProviderDomainId() === '') {
            return $this->fail(
                'Domain is not registered yet. Call register first.',
                Http::STATUS_PRECONDITION_FAILED,
            );
        }
        try {
            $data = $this->providerTools->verifyDomain((string)$entity->getProviderDomainId());
        } catch (ProviderToolsException $e) {
            $this->logger->warning('DMARC verify failed', [
                'app'       => Application::APP_ID,
                'exception' => $e,
            ]);
            return $this->fail($e->getMessage(), Http::STATUS_BAD_GATEWAY);
        }

        $verified = !empty($data['verified']);
        $entity->setProviderVerified($verified ? 1 : 0);
        $entity = $this->domains->update($entity);

        return new JSONResponse([
            'data'    => $this->hydrateDomain($entity),
            'result'  => [
                'verified' => $verified,
                'message'  => (string)($data['message'] ?? ''),
                'expected' => $data['expected'] ?? null,
                'found'    => $data['found']    ?? null,
            ],
        ]);
    }

    /**
     * Aggregate DMARC/SPF/DKIM statistics for the managed domain
     * (defaults to 30 days, accepts `?days=7|30|90`).
     */
    #[NoAdminRequired]
    public function stats(): JSONResponse {
        $entity = $this->managed->getOrCreate();
        if ($entity === null || (string)$entity->getProviderDomainId() === '') {
            return $this->fail(
                'Domain must be registered first.',
                Http::STATUS_PRECONDITION_FAILED,
            );
        }
        $days = (int)$this->request->getParam('days', 30);
        try {
            $data = $this->providerTools->getDomainStats((string)$entity->getProviderDomainId(), $days);
        } catch (ProviderToolsException $e) {
            return $this->fail($e->getMessage(), Http::STATUS_BAD_GATEWAY);
        }
        return new JSONResponse(['data' => $data]);
    }

    /**
     * Paginated aggregate reports (RUA) for the managed domain.
     */
    #[NoAdminRequired]
    public function reports(): JSONResponse {
        $entity = $this->managed->getOrCreate();
        if ($entity === null || (string)$entity->getProviderDomainId() === '') {
            return $this->fail(
                'Domain must be registered first.',
                Http::STATUS_PRECONDITION_FAILED,
            );
        }
        $page  = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, min(100, (int)$this->request->getParam('limit', 20)));
        try {
            $data = $this->providerTools->listAggregateReports(
                (string)$entity->getProviderDomainId(),
                $page,
                $limit,
            );
        } catch (ProviderToolsException $e) {
            return $this->fail($e->getMessage(), Http::STATUS_BAD_GATEWAY);
        }
        return new JSONResponse(['data' => $data]);
    }

    // -------------------------------------------------------------------
    // Mail tests
    // -------------------------------------------------------------------

    #[NoAdminRequired]
    public function listTests(): JSONResponse {
        $limit = min(500, max(10, (int)$this->request->getParam('limit', 100)));
        $rows = $this->tests->findRecent($limit);
        return new JSONResponse([
            'data' => array_map(fn(MailTest $t) => $this->hydrateTest($t), $rows),
        ]);
    }

    /**
     * Kick off a manual mail-test.
     *
     * If the underlying `MailTestService::run()` produces an entity in
     * `error` state (missing token, SMTP rejection, …) we roll it back
     * from the history and surface the error as HTTP 502 so the frontend
     * can toast it directly – no misleading "Test started" message
     * followed by a silent error row.
     */
    #[NoAdminRequired]
    public function triggerTest(): JSONResponse {
        $entity = $this->managed->getOrCreate();
        if ($entity === null) {
            return $this->fail('No managed domain configured.', Http::STATUS_FAILED_DEPENDENCY);
        }
        try {
            $test = $this->mailTestService->run(
                $entity,
                MailTest::TRIGGER_MANUAL,
                $this->currentUid(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Mail-test trigger failed', ['app' => Application::APP_ID, 'exception' => $e]);
            return $this->fail($e->getMessage(), Http::STATUS_BAD_GATEWAY);
        }

        if ($test->getStatus() === MailTest::STATUS_ERROR) {
            $errorMsg = $test->getErrorMessage() ?? 'Mail-test failed for an unknown reason.';
            try {
                $this->tests->delete($test);
            } catch (\Throwable $e) {
                $this->logger->warning('Could not roll back failed mail-test row', [
                    'app'       => Application::APP_ID,
                    'exception' => $e,
                ]);
            }
            return $this->fail($errorMsg, Http::STATUS_BAD_GATEWAY);
        }

        return new JSONResponse(['data' => $this->hydrateTest($test)]);
    }

    #[NoAdminRequired]
    public function refreshTest(int $testId): JSONResponse {
        try {
            $entity = $this->tests->findById($testId);
        } catch (DoesNotExistException) {
            return $this->fail('Test not found.', Http::STATUS_NOT_FOUND);
        }
        $entity = $this->mailTestService->refreshResult($entity);
        return new JSONResponse(['data' => $this->hydrateTest($entity)]);
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    /**
     * Pull the latest verified-flag / provider_domain_id from
     * provider.tools's list. Silent no-op on network errors so a
     * temporarily unreachable API does not brick the UI.
     */
    private function syncFromProvider(DmarcDomain $entity): void {
        if (!$this->providerTools->isConfigured()) {
            return;
        }
        try {
            $rows = $this->providerTools->listRegisteredDomains();
        } catch (ProviderToolsException) {
            return;
        }
        foreach ($rows as $row) {
            if (strcasecmp((string)($row['domain'] ?? ''), $entity->getDomain()) !== 0) {
                continue;
            }
            $changed = false;
            $id = (string)($row['id'] ?? '');
            if ($id !== '' && $entity->getProviderDomainId() !== $id) {
                $entity->setProviderDomainId($id);
                $changed = true;
            }
            $verified = !empty($row['verified']) ? 1 : 0;
            if ($entity->getProviderVerified() !== $verified) {
                $entity->setProviderVerified($verified);
                $changed = true;
            }
            if (isset($row['reportEmail']) && (string)$row['reportEmail'] !== (string)$entity->getReportEmail()) {
                $entity->setReportEmail((string)$row['reportEmail'] ?: null);
                $changed = true;
            }
            if (isset($row['dmarcRecord']) && (string)$row['dmarcRecord'] !== (string)$entity->getDmarcRecord()) {
                $entity->setDmarcRecord((string)$row['dmarcRecord'] ?: null);
                $changed = true;
            }
            if ($changed) {
                $this->domains->update($entity);
            }
            return;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function hydrateDomain(DmarcDomain $d): array {
        // Latest completed test (for the score badge).
        $latest = null;
        foreach ($this->tests->findRecent(50, (int)$d->getId()) as $t) {
            if ($t->getStatus() === MailTest::STATUS_COMPLETED) {
                $latest = $this->hydrateTest($t);
                break;
            }
        }
        $isRegistered = (string)$d->getProviderDomainId() !== '';
        return [
            'id'                  => (int)$d->getId(),
            'domain'              => $d->getDomain(),
            'sender_address'      => $d->getSenderAddress(),
            'provider_domain_id'  => $d->getProviderDomainId(),
            'is_registered'       => $isRegistered,
            'provider_verified'   => (int)$d->getProviderVerified() === 1,
            'verification_txt'    => $d->getVerificationTxt(),
            'verification_host'   => '_provider-tools.' . $d->getDomain(),
            'report_email'        => $d->getReportEmail(),
            'dmarc_record'        => $d->getDmarcRecord(),
            'dmarc_host'          => '_dmarc.' . $d->getDomain(),
            'registered_at'       => $d->getRegisteredAt(),
            'latest_test'         => $latest,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function hydrateTest(MailTest $t): array {
        return [
            'id'            => (int)$t->getId(),
            'domain_id'     => (int)$t->getDomainId(),
            'test_id'       => $t->getTestId(),
            'test_email'    => $t->getTestEmail(),
            'status'        => $t->getStatus(),
            'score'         => $t->getScore(),
            'spf'           => $t->getSpfResult(),
            'dkim'          => $t->getDkimResult(),
            'dmarc'         => $t->getDmarcResult(),
            'error'         => $t->getErrorMessage(),
            'trigger_type'  => $t->getTriggerType(),
            'triggered_by'  => $t->getTriggeredBy(),
            'created_at'    => (int)$t->getCreatedAt(),
            'sent_at'       => $t->getSentAt(),
            'completed_at'  => $t->getCompletedAt(),
        ];
    }

    private function fail(string $msg, int $status): JSONResponse {
        return new JSONResponse(['error' => $msg], $status);
    }

    private function currentUid(): string {
        $u = $this->userSession->getUser();
        return $u !== null ? $u->getUID() : 'system';
    }
}
