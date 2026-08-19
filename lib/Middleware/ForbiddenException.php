<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Middleware;

/**
 * Internal marker exception raised by {@see GroupRestrictionMiddleware}
 * when a non-member tries to access Souvera Shield.
 */
class ForbiddenException extends \RuntimeException {
    public function __construct(private readonly bool $api) {
        parent::__construct('souvera-users membership required');
    }

    public function isApi(): bool {
        return $this->api;
    }
}
