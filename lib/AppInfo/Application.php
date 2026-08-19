<?php
declare(strict_types=1);

namespace OCA\SouveraShield\AppInfo;

use OCA\SouveraShield\BackgroundJob\PollPendingMailTestsJob;
use OCA\SouveraShield\BackgroundJob\PollQuarantineJob;
use OCA\SouveraShield\BackgroundJob\ReputationAnalysisJob;
use OCA\SouveraShield\BackgroundJob\ScoreLoginTracesJob;
use OCA\SouveraShield\BackgroundJob\UpdateBaselinesJob;
use OCA\SouveraShield\BackgroundJob\CleanupLoginTracesJob;
use OCA\SouveraShield\BackgroundJob\WeeklyMailTestJob;
use OCA\SouveraShield\Dashboard\QuarantineWidget;
use OCA\SouveraShield\Notification\Notifier;
use OCA\SouveraShield\Notification\SuspiciousLoginNotifier;
use OCA\SouveraShield\Search\QuarantineSearchProvider;
use OCA\SouveraShield\Service\LoginTracker;
use OCA\SouveraShield\Service\IdentityDiscoveryService;
use OCA\SouveraShield\Service\PMGClient;
use OCA\SouveraShield\SetupChecks\FileIntegrityCheck;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\User\Events\UserLoggedInEvent;
use OCP\Authentication\Events\LoginFailedEvent;

/**
 * Souvera Shield – application bootstrap.
 *
 * Registers:
 *   - PMGClient                 service
 *   - ProviderToolsClient       service (DMARC + mail-test).
 *                               The provider.tools Bearer token is fetched
 *                               from souvera_central at runtime – Shield
 *                               does not store it locally.
 *   - Dashboard widget          (NC Dashboard API)
 *   - Notifier                  (bell notifications)
 *   - Unified search provider   (Ctrl+K palette)
 *   - Background jobs           (quarantine poll, weekly mail-test, mail-test poll)
 *   - Middlewares               (souvera-users all-app gate, souvera-admins DMARC gate)
 *
 * Global settings (desktop_notifications, daily_summary, min_spam_score) are
 * owned by the Souvera Central app – Shield only reads them through
 * {@see \OCA\SouveraShield\Service\CentralSettings}.
 */
class Application extends App implements IBootstrap {

    public const APP_ID       = 'souvera_shield';
    public const ALLOWED_GROUP = 'souvera-users';
    public const ADMIN_GROUP   = 'souvera-admins';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Service registrations
        $context->registerService(PMGClient::class, function ($c) {
            return new PMGClient(
                $c->get(\OCP\IConfig::class),
                $c->get(\OCP\IAppConfig::class),
                $c->get(\OCP\Http\Client\IClientService::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(\OCP\Security\ICrypto::class),
            );
        });

        $context->registerService(IdentityDiscoveryService::class, function ($c) {
            return new IdentityDiscoveryService(
                $c->get(\OCP\IUserSession::class),
                $c->get(\Psr\Log\LoggerInterface::class),
            );
        });

        // Dashboard widget
        $context->registerDashboardWidget(QuarantineWidget::class);
        $context->registerDashboardWidget(\OCA\SouveraShield\Dashboard\ArchiveComplianceWidget::class);

        // Notifier
        $context->registerNotifierService(Notifier::class);
        $context->registerNotifierService(SuspiciousLoginNotifier::class);

        // Unified search
        $context->registerSearchProvider(QuarantineSearchProvider::class);

        // Settings → Overview: verify all shipped files exist on this
        // server (guards against partial deployments on shared mounts).
        $context->registerSetupCheck(FileIntegrityCheck::class);

        // Defense-in-depth: hard-gate every request to our controllers
        // behind membership in the ALLOWED_GROUP.
        $context->registerMiddleware(\OCA\SouveraShield\Middleware\GroupRestrictionMiddleware::class);
        // Additional gate: only souvera-admins may hit the DmarcController.
        $context->registerMiddleware(\OCA\SouveraShield\Middleware\AdminAccessMiddleware::class);
    }

    public function boot(IBootContext $context): void {
        $context->injectFn(function (\OCP\BackgroundJob\IJobList $jobList): void {
            foreach ([
                PollQuarantineJob::class,
                WeeklyMailTestJob::class,
                PollPendingMailTestsJob::class,
                ReputationAnalysisJob::class,
                ScoreLoginTracesJob::class,
                UpdateBaselinesJob::class,
                CleanupLoginTracesJob::class,
                \OCA\SouveraShield\BackgroundJob\ArchiveIntegrityCheckJob::class,
            ] as $job) {
                if (!$jobList->has($job, null)) {
                    $jobList->add($job);
                }
            }
        });

        $context->injectFn(function (\OCP\EventDispatcher\IEventDispatcher $dispatcher): void {
            try {
                $dispatcher->addListener(UserLoggedInEvent::class, function (UserLoggedInEvent $event): void {
                    try {
                        $tracker = \OCP\Server::get(LoginTracker::class);
                        $request = \OCP\Server::get(\OCP\IRequest::class);
                        $tracker->onUserLoggedIn($event, $request);
                    } catch (\Throwable) {}
                });
                $dispatcher->addListener(LoginFailedEvent::class, function (LoginFailedEvent $event): void {
                    try {
                        $tracker = \OCP\Server::get(LoginTracker::class);
                        $request = \OCP\Server::get(\OCP\IRequest::class);
                        $tracker->onLoginFailed($event, $request);
                    } catch (\Throwable) {}
                });
                \OCP\Server::get(\Psr\Log\LoggerInterface::class)->info(
                    'souvera_shield: login tracking registered'
                );
            } catch (\Throwable $e) {
                \OCP\Server::get(\Psr\Log\LoggerInterface::class)->error(
                    'souvera_shield: login tracking registration FAILED: ' . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        });
    }
}
