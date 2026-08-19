<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Db\LoginTrace;
use OCA\SouveraShield\Db\LoginTraceMapper;
use OCA\SouveraShield\Db\LoginBaselineMapper;
use OCA\SouveraShield\Db\SuspiciousEventMapper;
use OCA\SouveraShield\Db\LoginFeedbackMapper;
use OCP\User\Events\UserLoggedInEvent;
use OCP\Authentication\Events\LoginFailedEvent;
use Psr\Log\LoggerInterface;

/**
 * Records every login attempt (success or failure) as a lightweight trace.
 * Scoring and event creation happen asynchronously via ScoreLoginTracesJob.
 *
 * Also acts as an event listener for Nextcloud's login events.
 */
class LoginTracker {

    public function __construct(
        private readonly LoginTraceMapper $traceMapper,
        private readonly LoginBaselineMapper $baselineMapper,
        private readonly SuspiciousEventMapper $eventMapper,
        private readonly LoginFeedbackMapper $feedbackMapper,
        private readonly IpEnrichmentService $ipEnrichment,
        private readonly SuspiciousLoginRules $rules,
        private readonly CentralSettings $central,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Record a login attempt (called from event listeners).
     */
    public function recordLogin(string $userId, string $ip, bool $success, ?string $userAgent): void {
        if ($userId === '' || $ip === '') {
            return;
        }

        // Dedup: skip if this user+IP logged in within the last 10 seconds.
        $recent = $this->traceMapper->countSince($userId, time() - 10);
        if ($recent > 0) {
            $this->logger->debug('Login trace deduped (within 10s)', [
                'app'     => Application::APP_ID,
                'user_id' => $userId,
                'ip'      => $ip,
                'recent'  => $recent,
            ]);
            return;
        }

        $subnet = $this->ipEnrichment->subnet($ip);
        $deviceHash = $this->ipEnrichment->deviceHash($subnet, $userAgent);

        $trace = new LoginTrace();
        $trace->setUserId($userId);
        $trace->setIp($ip);
        $trace->setIpSubnet($subnet);
        $trace->setSuccess($success ? 1 : 0);
        $trace->setUserAgent($userAgent);
        $trace->setDeviceHash($deviceHash);
        $trace->setCreatedAt(time());

        try {
            $this->traceMapper->insert($trace);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to record login trace', [
                'app'       => Application::APP_ID,
                'user_id'   => $userId,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Record a failed login attempt.
     */
    public function recordFailed(string $userId, string $ip, ?string $userAgent): void {
        $this->recordLogin($userId, $ip, false, $userAgent);
    }

    /**
     * Handle UserLoggedInEvent — extract data and record trace.
     */
    public function onUserLoggedIn(UserLoggedInEvent $event, \OCP\IRequest $request): void {
        $userId = $event->getUser()->getUID();
        $ip = $this->getClientIp($request);
        $userAgent = $this->getUserAgent($request);

        $this->logger->info('Login event received', [
            'app'     => Application::APP_ID,
            'user_id' => $userId,
            'ip'      => $ip,
        ]);

        $this->recordLogin($userId, $ip, true, $userAgent);
    }

    /**
     * Handle LoginFailedEvent — extract data and record failed trace.
     */
    public function onLoginFailed(LoginFailedEvent $event, \OCP\IRequest $request): void {
        // LoginFailedEvent has the login name as a private property with no public getter.
        // Try the POST form field 'user', then HTTP basic auth (DAV/API brute force),
        // as fallbacks for the attempted username.
        $userId = (string) ($request->getParam('user') ?? '');
        if ($userId === '') {
            $userId = $this->basicAuthUser($request);
        }
        $ip = $this->getClientIp($request);
        $userAgent = $this->getUserAgent($request);

        $this->recordFailed($userId, $ip, $userAgent);
    }

    /** Extracts the username from an HTTP Basic Authorization header, if present. */
    private function basicAuthUser(\OCP\IRequest $request): string {
        try {
            $auth = $request->getHeader('Authorization');
            if (\str_starts_with($auth, 'Basic ')) {
                $decoded = \base64_decode(\substr($auth, 6), true);
                if ($decoded !== false && \str_contains($decoded, ':')) {
                    return \explode(':', $decoded, 2)[0];
                }
            }
        } catch (\Throwable) {
        }
        return '';
    }

    private function getUserAgent(\OCP\IRequest $request): string {
        try {
            $ua = $request->getHeader('User-Agent');
            return $ua !== '' ? $ua : 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * Resolves the real client IP.
     *
     * Uses Nextcloud's own remote-address resolution (getRemoteAddress()),
     * which honors `trusted_proxies` + `forwarded_for_headers` from
     * config.php. NEVER parse X-Forwarded-For manually: without a proxy that
     * overwrites the header, the first entry is client-supplied and fully
     * spoofable — an attacker could poison baselines and scoring with
     * arbitrary IPs. Operators behind a reverse proxy must configure
     * `trusted_proxies` accordingly.
     */
    private function getClientIp(\OCP\IRequest $request): string {
        try {
            $ip = $request->getRemoteAddress();
            return $ip !== '' ? $ip : 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
