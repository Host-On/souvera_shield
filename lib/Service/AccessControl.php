<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service;

use OCA\SouveraShield\AppInfo\Application;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Centralised group-based access control for Souvera Shield.
 *
 * The whole app is restricted to a single Nextcloud group whose name lives in
 * {@see Application::ALLOWED_GROUP}. Members of that group (plus server admins,
 * for ops convenience) may use the app; everyone else gets a hard 403.
 *
 * This is the defence-in-depth layer that protects every controller; the
 * primary restriction is set declaratively through the NC app manager in
 * {@see \OCA\SouveraShield\Migration\RestrictToSouveraUsersGroupRepairStep}.
 */
class AccessControl {

    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
    }

    public function isCurrentUserAllowed(): bool {
        return $this->isAllowed($this->userSession->getUser());
    }

    public function isAllowed(?IUser $user): bool {
        if ($user === null) {
            return false;
        }
        if ($this->groupManager->isAdmin($user->getUID())) {
            // Server admins always have access – needed for diagnostics / occ.
            return true;
        }
        // Members of ALLOWED_GROUP or ADMIN_GROUP may use the app.
        // ADMIN_GROUP is implicitly a superset (an admin is always a user).
        return $this->groupManager->isInGroup($user->getUID(), Application::ALLOWED_GROUP)
            || $this->groupManager->isInGroup($user->getUID(), Application::ADMIN_GROUP);
    }
}
