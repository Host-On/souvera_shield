<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Controller;

use OCA\SouveraShield\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

/**
 * Renders the single-page Souvera Shield UI.
 *
 * The frontend is a Vue 3 SPA (SOUVERA_DESIGN_SYSTEM.md §2). Every "page"
 * route serves the same `main` template. The Vue router derives its
 * initial view from `window.location.pathname`.
 *
 * Feature flags are exposed as data-* attributes on the mount point
 * instead of an inline <script>, so we never touch Nextcloud's CSP
 * nonce manager (whose surface has shifted between minor versions).
 */
class PageController extends Controller {

    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): Response { return $this->renderPage('overview'); }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function quarantine(): Response { return $this->renderPage('quarantine'); }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function whitelist(): Response { return $this->renderPage('whitelist'); }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function blacklist(): Response { return $this->renderPage('blacklist'); }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function fileQuarantine(): Response {
        if (!$this->isFeatureEnabled('allow_file_quarantine')) {
            return $this->forbidden();
        }
        return $this->renderPage('file_quarantine');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function virusQuarantine(): Response {
        if (!$this->isFeatureEnabled('allow_virus_quarantine')) {
            return $this->forbidden();
        }
        return $this->renderPage('virus_quarantine');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function settings(): Response {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        return $this->renderPage('settings');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function audit(): Response {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        return $this->renderPage('audit');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function suspiciousLogin(): Response { return $this->renderPage('suspicious_login'); }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function dmarc(): Response { return $this->souveraAdminPage('dmarc'); }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function repProviders(): Response { return $this->souveraAdminPage('rep_providers'); }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function repChecks(): Response { return $this->souveraAdminPage('rep_checks'); }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function repSources(): Response { return $this->souveraAdminPage('rep_sources'); }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function repIncidents(): Response { return $this->souveraAdminPage('rep_incidents'); }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function repMailTests(): Response { return $this->souveraAdminPage('rep_mailtests'); }

    private function souveraAdminPage(string $page): Response {
        if (!$this->isSouveraAdmin()) {
            return $this->forbidden();
        }
        return $this->renderPage($page);
    }

    private function renderPage(string $page): TemplateResponse {
        Util::addScript(Application::APP_ID, 'souvera_shield-main');
        Util::addStyle (Application::APP_ID, 'main');

        return new TemplateResponse(Application::APP_ID, 'main', [
            'appVersion'            => $this->appVersion(),
            'initialPage'           => $page,
            'isAdmin'               => $this->isAdmin(),
            'isSouveraAdmin'        => $this->isSouveraAdmin(),
            'allowFileQuarantine'   => $this->isFeatureEnabled('allow_file_quarantine'),
            'allowVirusQuarantine'  => $this->isFeatureEnabled('allow_virus_quarantine'),
        ]);
    }

    private function forbidden(): TemplateResponse {
        return new TemplateResponse(
            'core',
            '403',
            ['message' => 'You are not allowed to access this page.'],
            TemplateResponse::RENDER_AS_GUEST,
        );
    }

    private function isAdmin(): bool {
        $user = $this->userSession->getUser();
        return $user !== null && $this->groupManager->isAdmin($user->getUID());
    }

    private function isSouveraAdmin(): bool {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }
        if ($this->groupManager->isAdmin($user->getUID())) {
            return true;
        }
        return $this->groupManager->isInGroup($user->getUID(), Application::ADMIN_GROUP);
    }

    private function isFeatureEnabled(string $key): bool {
        return $this->appConfig->getValueBool(Application::APP_ID, $key, true, lazy: true);
    }

    private function appVersion(): string {
        // Resolved lazily via the server container instead of constructor
        // injection: on multi-node deployments with the app on a shared
        // mount, a stale opcache can briefly serve an older compiled
        // constructor, leaving injected properties null. Server::get()
        // plus the info.xml fallback below is immune to that.
        try {
            $version = \OCP\Server::get(IAppManager::class)->getAppVersion(Application::APP_ID);
            if ($version !== '' && $version !== '0.0.0') {
                return $version;
            }
        } catch (\Throwable) {
            // fall through to the info.xml fallback
        }

        $xmlPath = dirname(__DIR__, 2) . '/appinfo/info.xml';
        if (is_file($xmlPath)) {
            $xml = @simplexml_load_file($xmlPath);
            if ($xml !== false && isset($xml->version)) {
                return (string)$xml->version;
            }
        }
        return '0.0.0';
    }
}
