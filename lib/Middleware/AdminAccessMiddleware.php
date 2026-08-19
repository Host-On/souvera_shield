<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Middleware;

use OCA\SouveraShield\Controller\DmarcController;
use OCA\SouveraShield\Controller\ReputationController;
use OCA\SouveraShield\Service\AdminAccessControl;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;

/**
 * Additional gate that protects {@see DmarcController}: only members of the
 * `souvera-admins` group (or server admins) may reach any of its endpoints.
 *
 * Runs *after* {@see GroupRestrictionMiddleware} which already restricts the
 * whole app to the `souvera-users` group.
 */
class AdminAccessMiddleware extends Middleware {

    public function __construct(
        private readonly AdminAccessControl $access,
    ) {
    }

    public function beforeController($controller, $methodName): void {
        if (!($controller instanceof DmarcController) && !($controller instanceof ReputationController)) {
            return;
        }
        if ($this->access->isCurrentUserAdmin()) {
            return;
        }
        throw new ForbiddenException(true);
    }

    public function afterException($controller, $methodName, \Throwable $exception): Response {
        if (!($exception instanceof ForbiddenException)) {
            throw $exception;
        }
        return new JSONResponse(
            ['error' => 'Forbidden: membership in the souvera-admins group is required.'],
            Http::STATUS_FORBIDDEN,
        );
    }
}
