<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Middleware;

use OCA\SouveraShield\Controller\ApiController;
use OCA\SouveraShield\Controller\PageController;
use OCA\SouveraShield\Service\AccessControl;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;

/**
 * Hard-gates every request to Souvera Shield's own controllers behind
 * membership of {@see \OCA\SouveraShield\AppInfo\Application::ALLOWED_GROUP}.
 *
 * This runs in addition to Nextcloud's own group-restricted app activation
 * (set by the repair step on install/upgrade) so the app stays safe even if
 * an admin manually removes the restriction via `occ`.
 *
 * Response shape:
 *   - REST endpoints (ApiController)  → JSON `403 { "error": "Forbidden" }`
 *   - Page routes      (PageController) → HTML `403` (NC core template)
 */
class GroupRestrictionMiddleware extends Middleware {

    public function __construct(
        private readonly AccessControl $access,
    ) {
    }

    /**
     * @param Controller $controller
     * @param string     $methodName
     */
    public function beforeController($controller, $methodName): void {
        if (!($controller instanceof ApiController) && !($controller instanceof PageController)) {
            return;
        }
        if ($this->access->isCurrentUserAllowed()) {
            return;
        }
        throw new ForbiddenException($controller instanceof ApiController);
    }

    /**
     * @param Controller  $controller
     * @param string      $methodName
     * @param \Throwable  $exception
     */
    public function afterException($controller, $methodName, \Throwable $exception): Response {
        if (!($exception instanceof ForbiddenException)) {
            throw $exception;
        }
        if ($exception->isApi()) {
            return new JSONResponse(
                ['error' => 'Forbidden: membership in the souvera-users group is required.'],
                Http::STATUS_FORBIDDEN,
            );
        }
        return new TemplateResponse(
            'core',
            '403',
            ['message' => 'Souvera Shield is restricted to the souvera-users group.'],
            TemplateResponse::RENDER_AS_GUEST,
        );
    }
}
