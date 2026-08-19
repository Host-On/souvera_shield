<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service;

use OCA\SouveraShield\AppInfo\Application;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Restricts the DMARC / mail-test admin area of Souvera Shield to the
 * dedicated `souvera-admins` group.
 *
 * Server-level administrators keep access at all times so they can diagnose
 * problems even if group membership has not been synchronised yet.
 */
class AdminAccessControl {

    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
    }

    public function isCurrentUserAdmin(): bool {
        return $this->isAdmin($this->userSession->getUser());
    }

    public function isAdmin(?IUser $user): bool {
        if ($user === null) {
            return false;
        }
        if ($this->groupManager->isAdmin($user->getUID())) {
            return true;
        }
        return $this->groupManager->isInGroup($user->getUID(), Application::ADMIN_GROUP);
    }
}
