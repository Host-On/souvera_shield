<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit\Service;

use OCA\SouveraShield\Service\ProviderToolsClient;
use OCA\SouveraShield\Service\ProviderToolsException;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ProviderToolsClient.
 *
 * The token is now fetched from souvera_central via
 * `\OCP\Server::get(ProviderTokenService::class)`. That call is static and
 * therefore not directly mockable – but the client guards it with
 * `IAppManager::isInstalled('souvera_central')`. These tests exercise the
 * guard so we know Shield behaves correctly when Central is missing.
 */
class ProviderToolsClientTest extends TestCase {

    private IAppConfig $appConfig;
    private IClient $client;
    private IClientService $clientService;
    private LoggerInterface $logger;
    private IAppManager $appManager;

    protected function setUp(): void {
        parent::setUp();
        $this->appConfig     = $this->createMock(IAppConfig::class);
        $this->client        = $this->createMock(IClient::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->appManager    = $this->createMock(IAppManager::class);

        $this->clientService = $this->createMock(IClientService::class);
        $this->clientService->method('newClient')->willReturn($this->client);

        // No local overrides -> default base URL.
        $this->appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = '', bool $lazy = false): string {
                return $default;
            }
        );
    }

    private function newClient(): ProviderToolsClient {
        return new ProviderToolsClient(
            $this->appConfig,
            $this->clientService,
            $this->logger,
            $this->appManager,
        );
    }

    public function testIsConfiguredReturnsFalseWhenCentralIsNotInstalled(): void {
        $this->appManager->method('isInstalled')
            ->with('souvera_central')
            ->willReturn(false);

        $client = $this->newClient();
        $this->assertFalse($client->isConfigured());
    }

    public function testCheckDmarcThrowsWithClearMessageWhenCentralMissing(): void {
        $this->appManager->method('isInstalled')
            ->with('souvera_central')
            ->willReturn(false);

        // No HTTP call must happen when the token can't be resolved.
        $this->client->expects($this->never())->method('get');
        $this->client->expects($this->never())->method('post');

        $client = $this->newClient();
        $this->expectException(ProviderToolsException::class);
        $this->expectExceptionMessage('Reputation service token is not configured in Souvera Central');
        $client->checkDmarc('example.com');
    }

    public function testCreateMailTestThrowsWithClearMessageWhenCentralMissing(): void {
        $this->appManager->method('isInstalled')
            ->with('souvera_central')
            ->willReturn(false);

        $this->client->expects($this->never())->method('post');

        $client = $this->newClient();
        $this->expectException(ProviderToolsException::class);
        $this->expectExceptionMessage('occ souvera:provider-token:set');
        $client->createMailTest();
    }

    public function testDefaultBaseUrlIsUsedWhenNoOverride(): void {
        $this->assertSame(
            'https://provider.tools/api/v1',
            ProviderToolsClient::DEFAULT_BASE_URL,
        );
    }
}
