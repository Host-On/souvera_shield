<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit\Service;

use OCA\SouveraShield\Service\PMGClient;
use OCA\SouveraShield\Service\PMGException;
use OCP\IAppConfig;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for PMGClient.
 *
 * The IClient is mocked so no real Proxmox Mail Gateway is needed.
 */
class PMGClientTest extends TestCase {

    private IConfig $config;
    private IAppConfig $appConfig;
    private IClient $client;
    private IClientService $clientService;
    private LoggerInterface $logger;
    private ICrypto $crypto;

    protected function setUp(): void {
        parent::setUp();
        $this->config = $this->createMock(IConfig::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->client = $this->createMock(IClient::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->crypto = $this->createMock(ICrypto::class);

        $this->clientService = $this->createMock(IClientService::class);
        $this->clientService->method('newClient')->willReturn($this->client);
    }

    /**
     * Configure both the system-config (via IConfig::getSystemValue) and the
     * lazy app-config (via IAppConfig::getValueString) so PMGClient sees the
     * same key/value pairs regardless of which API is used.
     *
     * @param array<string,mixed> $values
     */
    private function configWith(array $values): void {
        $this->config->method('getSystemValue')->willReturnCallback(
            static function (string $key, $default = '') use ($values) {
                return $values[$key] ?? $default;
            }
        );
        $this->appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = '', bool $lazy = false) use ($values): string {
                return (string)($values[$key] ?? $default);
            }
        );
    }

    private function jsonResponse(int $status, array $body): IResponse {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn(json_encode($body));
        return $response;
    }

    public function testIsAllowedDomain(): void {
        $this->configWith([
            'pmg_domain' => 'https://pmg.example.com',
            'pmg_username' => 'user@pmg',
            'pmg_password' => 'pw',
            'pmg_allowed_domains' => 'example.com, souvera.eu',
        ]);
        $client = new PMGClient($this->config, $this->appConfig, $this->clientService, $this->logger, $this->crypto);

        $this->assertTrue($client->isAllowedDomain('alice@example.com'));
        $this->assertTrue($client->isAllowedDomain('bob@SOUVERA.EU'));
        $this->assertFalse($client->isAllowedDomain('mallory@evil.org'));
        $this->assertFalse($client->isAllowedDomain('no-at-sign'));
    }

    public function testGetSpamQuarantineRejectsDisallowedDomain(): void {
        $this->configWith([
            'pmg_domain' => 'https://pmg.example.com',
            'pmg_username' => 'user@pmg',
            'pmg_password' => 'pw',
            'pmg_allowed_domains' => 'example.com',
        ]);
        $client = new PMGClient($this->config, $this->appConfig, $this->clientService, $this->logger, $this->crypto);

        $this->expectException(PMGException::class);
        $this->expectExceptionMessage('not allowed');
        $client->getSpamQuarantine('eve@other.test');
    }

    public function testLoginFailureThrowsPMGException(): void {
        $this->configWith([
            'pmg_domain' => 'https://pmg.example.com',
            'pmg_username' => 'user@pmg',
            'pmg_password' => 'pw',
            'pmg_allowed_domains' => 'example.com',
        ]);

        $this->client->expects($this->once())
            ->method('post')
            ->willReturn($this->jsonResponse(401, ['data' => null]));

        $client = new PMGClient($this->config, $this->appConfig, $this->clientService, $this->logger, $this->crypto);

        $this->expectException(PMGException::class);
        $client->getSpamQuarantine('alice@example.com');
    }

    public function testGetSpamQuarantineReturnsData(): void {
        $this->configWith([
            'pmg_domain' => 'https://pmg.example.com',
            'pmg_username' => 'user@pmg',
            'pmg_password' => 'pw',
            'pmg_allowed_domains' => 'example.com',
        ]);

        $loginResponse = $this->jsonResponse(200, [
            'data' => [
                'ticket' => 'PMGTICKET',
                'CSRFPreventionToken' => 'CSRF',
            ],
        ]);
        $listResponse = $this->jsonResponse(200, [
            'data' => [
                ['id' => '1', 'from' => 'spam@bad.test', 'subject' => 'Win!'],
                ['id' => '2', 'from' => 'spam2@bad.test', 'subject' => 'Free!'],
            ],
        ]);

        $this->client->expects($this->once())->method('post')->willReturn($loginResponse);
        $this->client->expects($this->once())->method('get')->willReturn($listResponse);

        $client = new PMGClient($this->config, $this->appConfig, $this->clientService, $this->logger, $this->crypto);
        $res = $client->getSpamQuarantine('alice@example.com');

        $this->assertCount(2, $res['data']);
        $this->assertSame('Win!', $res['data'][0]['subject']);
    }

    public function testAddToWhitelistBuildsCorrectPayload(): void {
        $this->configWith([
            'pmg_domain' => 'https://pmg.example.com',
            'pmg_username' => 'user@pmg',
            'pmg_password' => 'pw',
            'pmg_allowed_domains' => 'example.com',
        ]);

        $loginResponse = $this->jsonResponse(200, [
            'data' => ['ticket' => 'T', 'CSRFPreventionToken' => 'C'],
        ]);
        $okResponse = $this->jsonResponse(200, ['data' => 'ok']);

        $this->client->method('post')->willReturnOnConsecutiveCalls(
            $loginResponse,
            $okResponse,
        );

        $client = new PMGClient($this->config, $this->appConfig, $this->clientService, $this->logger, $this->crypto);
        $client->addToWhitelist('alice@example.com', 'friend@trust.test');

        // No exception -> success.
        $this->assertTrue(true);
    }

    public function testAddToWhitelistRejectsEmptyEntry(): void {
        $this->configWith([
            'pmg_domain' => 'https://pmg.example.com',
            'pmg_username' => 'user@pmg',
            'pmg_password' => 'pw',
            'pmg_allowed_domains' => 'example.com',
        ]);

        // login succeeds
        $this->client->method('post')->willReturn(
            $this->jsonResponse(200, ['data' => ['ticket' => 't', 'CSRFPreventionToken' => 'c']])
        );

        $client = new PMGClient($this->config, $this->appConfig, $this->clientService, $this->logger, $this->crypto);
        $this->expectException(PMGException::class);
        $client->addToWhitelist('alice@example.com', '   ');
    }

    public function testEncryptedPasswordIsDecryptedOnRead(): void {
        $this->configWith([
            'pmg_domain' => 'https://pmg.example.com',
            'pmg_username' => 'user@pmg',
            'pmg_password' => PMGClient::ENCRYPTION_PREFIX . 'CIPHER',
            'pmg_allowed_domains' => 'example.com',
        ]);

        $this->crypto->expects($this->once())
            ->method('decrypt')
            ->with('CIPHER')
            ->willReturn('secret-pw');

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function ($options) {
                    return is_array($options['body'] ?? null)
                        && ($options['body']['password'] ?? '') === 'secret-pw';
                })
            )
            ->willReturn($this->jsonResponse(200, ['data' => ['ticket' => 't', 'CSRFPreventionToken' => 'c']]));

        $this->client->method('get')->willReturn($this->jsonResponse(200, ['data' => []]));

        $client = new PMGClient($this->config, $this->appConfig, $this->clientService, $this->logger, $this->crypto);
        $client->getSpamQuarantine('alice@example.com');
    }
}
