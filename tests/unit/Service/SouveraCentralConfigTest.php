<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit\Service;

use OCA\SouveraShield\Service\SouveraCentralConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class SouveraCentralConfigTest extends TestCase {

    public function testNestedArrayLayout(): void {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(
            static function (string $key, $default = '') {
                if ($key === 'souvera_central') {
                    return [
                        'stalwart_api_url'   => 'https://mail.customer.example',
                        'stalwart_smtp_port' => '587',
                    ];
                }
                return $default;
            },
        );
        $central = new SouveraCentralConfig($config);
        $this->assertSame('https://mail.customer.example', $central->read('stalwart_api_url'));
        $this->assertSame('587', $central->read('stalwart_smtp_port'));
    }

    public function testFlatDottedLayout(): void {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(
            static function (string $key, $default = '') {
                return match ($key) {
                    'souvera_central'                        => [],
                    'souvera_central.stalwart_api_url'       => 'https://mail.other.example',
                    'souvera_central.stalwart_admin_user'    => 'admin',
                    default                                  => $default,
                };
            },
        );
        $central = new SouveraCentralConfig($config);
        $this->assertSame('https://mail.other.example', $central->read('stalwart_api_url'));
        $this->assertSame('admin', $central->read('stalwart_admin_user'));
    }

    public function testReturnsNullWhenNothingConfigured(): void {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(
            static fn(string $key, $default = '') => $default,
        );
        $central = new SouveraCentralConfig($config);
        $this->assertNull($central->read('stalwart_api_url'));
    }
}
