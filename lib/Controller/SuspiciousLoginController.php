<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Controller;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Db\SuspiciousEvent;
use OCA\SouveraShield\Db\SuspiciousEventMapper;
use OCA\SouveraShield\Db\LoginFeedback;
use OCA\SouveraShield\Db\LoginFeedbackMapper;
use OCA\SouveraShield\Db\LoginTraceMapper;
use OCA\SouveraShield\Service\AdminAccessControl;
use OCA\SouveraShield\Service\IpEnrichmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * REST API for Suspicious Login Detection.
 *
 * All authenticated souvera-users can see their OWN suspicious login events.
 * Souvera-admins see ALL events and can resolve them. The controller is NOT
 * gated by AdminAccessMiddleware — authorization is handled inline.
 */
class SuspiciousLoginController extends Controller {

    public function __construct(
        IRequest $request,
        private readonly SuspiciousEventMapper $eventMapper,
        private readonly LoginTraceMapper $traceMapper,
        private readonly LoginFeedbackMapper $feedbackMapper,
        private readonly IpEnrichmentService $ipEnrichment,
        private readonly AdminAccessControl $adminAccess,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * List suspicious events. Non-admins see only their own events.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(
        ?int $resolved = null,
        ?string $severity = null,
        ?string $userId = null,
        int $limit = 50,
        int $offset = 0,
    ): JSONResponse {
        $limit = min(200, max(1, $limit));
        $offset = max(0, $offset);

        // Non-admin users can only see their own events
        $effectiveUserId = $userId;
        if (!$this->adminAccess->isCurrentUserAdmin()) {
            $effectiveUserId = $this->currentUid();
        }

        $events = $this->eventMapper->findAll($resolved, $severity, $effectiveUserId, null, $limit, $offset);
        $total  = $this->eventMapper->countAll($resolved, $severity, $effectiveUserId);

        $data = array_map(fn(SuspiciousEvent $e) => $this->hydrateEvent($e), $events);

        return new JSONResponse([
            'data' => [
                'events' => $data,
                'total' => $total,
                'user' => [
                    'uid' => $this->currentUid(),
                    'is_admin' => $this->adminAccess->isCurrentUserAdmin(),
                ],
            ],
        ]);
    }

    /**
     * Single event detail. Non-admins can only view their own events.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(int $id): JSONResponse {
        try {
            $event = $this->eventMapper->findById($id);
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'Event not found.'], Http::STATUS_NOT_FOUND);
        }

        // Non-admin users can only view their own events
        if (!$this->adminAccess->isCurrentUserAdmin() && $event->getUserId() !== $this->currentUid()) {
            return new JSONResponse(['error' => 'Forbidden.'], Http::STATUS_FORBIDDEN);
        }

        $data = $this->hydrateEvent($event);

        $traceId = $event->getTraceId();
        if ($traceId !== null) {
            try {
                $trace = $this->traceMapper->findById((int)$traceId);
                $data['trace'] = [
                    'user_agent'  => $trace->getUserAgent(),
                    'device_hash' => $trace->getDeviceHash(),
                    'ip_subnet'   => $trace->getIpSubnet(),
                    'success'     => $trace->getSuccess(),
                    'asn'         => $trace->getAsn(),
                    'risk_score'  => $trace->getRiskScore(),
                    'rule_results' => $trace->getRuleResults() ? json_decode($trace->getRuleResults(), true) : null,
                ];
            } catch (\Throwable) {
            }
        }

        return new JSONResponse(['data' => $data]);
    }

    /**
     * Resolve a suspicious event. Admins can resolve with any feedback type.
     * Affected users can resolve their own events (false_positive / user_travel).
     */
    #[NoAdminRequired]
    public function resolve(int $id, string $feedback, ?string $notes = null): JSONResponse {
        try {
            $event = $this->eventMapper->findById($id);
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'Event not found.'], Http::STATUS_NOT_FOUND);
        }

        $isAdmin = $this->adminAccess->isCurrentUserAdmin();
        $currentUser = $this->currentUid();
        $isOwnEvent = $event->getUserId() === $currentUser;

        $this->logger->debug('SuspiciousLoginController::resolve called', [
            'event_id' => $id,
            'feedback' => $feedback,
            'current_uid' => $currentUser,
            'event_uid' => $event->getUserId(),
            'is_admin' => $isAdmin,
            'is_own_event' => $isOwnEvent,
        ]);

        // Admins can resolve anything. Affected users can resolve own events.
        if (!$isAdmin && !$isOwnEvent) {
            return new JSONResponse(['error' => 'Forbidden.'], Http::STATUS_FORBIDDEN);
        }

        $validFeedbacks = ['confirmed_threat', 'false_positive', 'user_travel', 'known_location'];
        if (!in_array($feedback, $validFeedbacks, true)) {
            return new JSONResponse([
                'error' => 'Invalid feedback type. Valid values: ' . implode(', ', $validFeedbacks),
            ], Http::STATUS_BAD_REQUEST);
        }

        // Non-admin users may only mark their own events as benign
        // (false_positive / user_travel / known_location). "confirmed_threat"
        // is an admin-only verdict.
        if (!$isAdmin && $feedback === 'confirmed_threat') {
            return new JSONResponse([
                'error' => 'Only admins may confirm a threat.',
            ], Http::STATUS_FORBIDDEN);
        }

        $currentUser = $this->currentUid();
        $now = time();

        $event->setResolved(1);
        $event->setResolvedBy($currentUser);
        $event->setResolvedAt($now);
        $this->eventMapper->update($event);

        // Store feedback for future scoring. Only admin-issued feedback is
        // honored by the scoring pipeline (is_admin flag) — self-service
        // feedback must never weaken detection for a (possibly compromised)
        // user's own location.
        $ip = $event->getIp();
        $subnet = $ip !== null ? $this->ipEnrichment->subnet($ip) : null;

        $feedbackEntity = new LoginFeedback();
        $feedbackEntity->setUserId($event->getUserId());
        $feedbackEntity->setIp($ip);
        $feedbackEntity->setIpSubnet($subnet);
        $feedbackEntity->setFeedback($feedback);
        $feedbackEntity->setCreatedBy($currentUser);
        $feedbackEntity->setIsAdmin($isAdmin ? 1 : 0);
        $feedbackEntity->setNotes($notes);
        $feedbackEntity->setCreatedAt($now);
        $this->feedbackMapper->insert($feedbackEntity);

        $this->logger->info('Suspicious event resolved', [
            'app'       => Application::APP_ID,
            'event_id'  => $id,
            'feedback'  => $feedback,
            'resolved_by' => $currentUser,
        ]);

        return new JSONResponse(['data' => $this->hydrateEvent($event)]);
    }

    /**
     * @return array<string,mixed>
     */
    private function hydrateEvent(SuspiciousEvent $e): array {
        return [
            'id'           => (int)$e->getId(),
            'user_id'      => $e->getUserId(),
            'trace_id'     => $e->getTraceId(),
            'confidence'   => $e->getConfidence(),
            'severity'     => $e->getSeverity(),
            'decision'     => $e->getDecision(),
            'ip'           => $e->getIp(),
            'geo_country'  => $e->getGeoCountry(),
            'geo_city'     => $e->getGeoCity(),
            'isp_name'     => $e->getIspName(),
            'risk_flags'   => $e->getRiskFlags() ? json_decode($e->getRiskFlags(), true) : null,
            'resolved'     => (int)$e->getResolved() === 1,
            'resolved_by'  => $e->getResolvedBy(),
            'resolved_at'  => $e->getResolvedAt(),
            'created_at'   => (int)$e->getCreatedAt(),
        ];
    }

    private function currentUid(): string {
        $u = $this->userSession->getUser();
        return $u !== null ? $u->getUID() : 'system';
    }
}
