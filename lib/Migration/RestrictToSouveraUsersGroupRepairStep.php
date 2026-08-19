<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Migration;

use OCA\SouveraShield\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Enforce that Souvera Shield is enabled for the two dedicated
 * groups only:
 *
 *   - {@see Application::ALLOWED_GROUP} = `souvera-users`  (regular users)
 *   - {@see Application::ADMIN_GROUP}   = `souvera-admins` (extra admin
 *     powers such as the Reputation area; admins are logically also users
 *     but Nextcloud groups are not hierarchical, so we must include both
 *     in the AppManager restriction – otherwise a `souvera-admins`-only
 *     member gets HTTP 404 on every app route).
 *
 * Also registers the Quarantine dashboard widget in Nextcloud's default
 * dashboard layout so users see the widget out of the box (they can still
 * remove it from their personal dashboard – we only touch the *default*,
 * never their custom layout).
 *
 * Runs on every install and upgrade ({@see info.xml /repair-steps/install
 * and /repair-steps/post-migration}). Idempotent:
 *
 *   1. Ensure both groups exist – create them on first install.
 *   2. Call IAppManager::enableAppForGroups() with BOTH groups, which is
 *      Nextcloud's officially supported mechanism. Side effects:
 *      • the app navigation icon is only shown to members of either group
 *      • app routes return 404 for non-members
 *      • our GroupRestrictionMiddleware adds defence-in-depth on top
 *      • the AdminAccessMiddleware further restricts the Reputation area
 *        to `souvera-admins` (plus server admins).
 *   3. Append `souvera_shield` to `dashboard.defaultLayout` (unless already
 *      present) so the widget is shown by default on every fresh account.
 */
class RestrictToSouveraUsersGroupRepairStep implements IRepairStep {

    private const DASHBOARD_APP           = 'dashboard';
    private const DASHBOARD_LAYOUT_KEY    = 'defaultLayout';
    /** Nextcloud's built-in default before we touch it. */
    private const DASHBOARD_LAYOUT_STOCK  = 'recommendations,spreed,mail,calendar';

    public function __construct(
        private readonly IGroupManager $groupManager,
        private readonly IAppManager $appManager,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string {
        return 'Restrict Souvera Shield to the '
            . Application::ALLOWED_GROUP . ' and '
            . Application::ADMIN_GROUP . ' groups';
    }

    public function run(IOutput $output): void {
        $groups = [];
        foreach ([Application::ALLOWED_GROUP, Application::ADMIN_GROUP] as $groupId) {
            $group = $this->ensureGroup($output, $groupId);
            if ($group !== null) {
                $groups[] = $group;
            } else {
                $output->warning('Could not create or load group "' . $groupId . '" – skipping.');
            }
        }

        if (empty($groups)) {
            $output->warning('No target groups available – app restriction skipped.');
        } else {
            try {
                $this->appManager->enableAppForGroups(Application::APP_ID, $groups);
                $names = array_map(static fn(IGroup $g) => $g->getGID(), $groups);
                $output->info('Souvera Shield enabled for groups: ' . implode(', ', $names));
            } catch (\Throwable $e) {
                $this->logger->error('Failed to restrict app to groups', ['exception' => $e]);
                $output->warning('Could not apply group restriction: ' . $e->getMessage());
            }
        }

        $this->addWidgetToDefaultDashboardLayout($output);
    }

    private function ensureGroup(IOutput $output, string $groupId): ?IGroup {
        $existing = $this->groupManager->get($groupId);
        if ($existing !== null) {
            return $existing;
        }
        $output->info('Group "' . $groupId . '" missing – creating it.');
        return $this->groupManager->createGroup($groupId);
    }

    /**
     * Nextcloud's dashboard reads `dashboard.defaultLayout` (a comma-
     * separated widget-id list) whenever a user opens the dashboard
     * without a personal layout stored. Appending our widget here means
     * every fresh account sees the Quarantine widget out of the box;
     * users who already customised their dashboard are untouched
     * because Nextcloud only falls back to `defaultLayout` when no
     * `dashboard/layout` user-config exists.
     */
    private function addWidgetToDefaultDashboardLayout(IOutput $output): void {
        try {
            $current = $this->appConfig->getValueString(
                self::DASHBOARD_APP,
                self::DASHBOARD_LAYOUT_KEY,
                self::DASHBOARD_LAYOUT_STOCK,
            );
            $ids = array_values(array_filter(array_map('trim', explode(',', $current))));
            if (in_array(Application::APP_ID, $ids, true)) {
                $output->info('Dashboard defaultLayout already contains souvera_shield.');
                return;
            }
            $ids[] = Application::APP_ID;
            $this->appConfig->setValueString(
                self::DASHBOARD_APP,
                self::DASHBOARD_LAYOUT_KEY,
                implode(',', $ids),
            );
            $output->info('Added souvera_shield to dashboard.defaultLayout: ' . implode(',', $ids));
        } catch (\Throwable $e) {
            // A missing dashboard app or a permission issue must not
            // abort the migration – just log and continue.
            $this->logger->warning('Could not update dashboard.defaultLayout', ['exception' => $e]);
            $output->warning('Could not update dashboard.defaultLayout: ' . $e->getMessage());
        }
    }
}
