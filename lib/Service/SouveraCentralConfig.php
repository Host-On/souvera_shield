<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service;

use OCP\IConfig;

/**
 * Read-only accessor for the `souvera_central.*` system-config keys.
 *
 * Supports both storage layouts found in the field:
 *   - nested array:   'souvera_central' => ['stalwart_api_url' => '…', …]
 *   - flat dotted:    'souvera_central.stalwart_api_url' => '…'
 */
class SouveraCentralConfig {

    private const CENTRAL_APP = 'souvera_central';

    public function __construct(
        private readonly IConfig $systemConfig,
    ) {
    }

    public function read(string $suffix): ?string {
        $val = $this->systemConfig->getSystemValue(self::CENTRAL_APP, []);
        if (is_array($val) && array_key_exists($suffix, $val)) {
            return (string)$val[$suffix];
        }
        $flat = $this->systemConfig->getSystemValue(self::CENTRAL_APP . '.' . $suffix, null);
        return $flat === null ? null : (string)$flat;
    }
}
