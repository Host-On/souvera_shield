<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service;

/**
 * Thrown for any error originating from the Proxmox Mail Gateway client.
 *
 * Carries an optional HTTP status code so the controller can map it to the
 * correct API response without leaking PMG internals.
 */
class PMGException extends \RuntimeException {

    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatus(): int {
        return $this->httpStatus;
    }
}
