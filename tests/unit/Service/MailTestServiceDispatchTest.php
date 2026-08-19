<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit\Service;

use OCA\SouveraShield\Db\DmarcDomain;
use OCA\SouveraShield\Db\MailTestMapper;
use OCA\SouveraShield\Service\MailTestRelayConfig;
use OCA\SouveraShield\Service\MailTestRelayException;
use OCA\SouveraShield\Service\MailTestService;
use OCA\SouveraShield\Service\ManagedDomainService;
use OCA\SouveraShield\Service\ProviderToolsClient;
use OCA\SouveraShield\Service\Reputation\AnalysisRunner;
use OCA\SouveraShield\Service\SmtpMailTestRelay;
use OCA\SouveraShield\Service\SouveraCentralConfig;
use OCA\SouveraShield\Tests\Unit\L10NTestHelper;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Locks down the v3.8.0 credential-resolution and dispatch behaviour of
 * {@see MailTestService::dispatchEmail()}, plus the v3.9.0 English source
 * strings.
 */
class MailTestServiceDispatchTest extends TestCase {

    use L10NTestHelper;

    private function service(array $central, SmtpMailTestRelay $relay): MailTestService {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(
            static fn(string $key, $default = '') => $key === 'souvera_central' ? $central : $default,
        );
        return new MailTestService(
            $this->createMock(ProviderToolsClient::class),
            $this->createMock(MailTestMapper::class),
            new SouveraCentralConfig($config),
            $relay,
            $this->createMock(IAppConfig::class),
            $this->createMock(ManagedDomainService::class),
            $this->createMock(AnalysisRunner::class),
            $this->createMock(LoggerInterface::class),
            $this->l10nFactory(),
        );
    }

    private function invokeDispatch(MailTestService $svc, string $domainName = 'kunde.example.org'): void {
        $domain = new DmarcDomain();
        $domain->setDomain($domainName);
        $ref = new \ReflectionMethod($svc, 'dispatchEmail');
        $ref->setAccessible(true);
        $ref->invoke($svc, $domain, 'abc123@chk.provider.tools');
    }

    private function centralWithStalwartUrl(array $overrides = []): array {
        return array_merge([
            'stalwart_api_url' => 'https://10.0.0.20:8080',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Default flow: anonymous / trusted-IP submission
    // ------------------------------------------------------------------

    public function testDefaultFlowIsAnonymousSubmission(): void {
        $relay = $this->createMock(SmtpMailTestRelay::class);
        $relay->expects($this->once())->method('send')->willReturnCallback(
            function (MailTestRelayConfig $config, string $fromAddress): void {
                $this->assertSame('postmaster@kunde.example.org', $fromAddress);
                $this->assertSame('', $config->smtpUser, 'anonymous flow must not present an AUTH principal');
                $this->assertSame('', $config->smtpPassword);
                $this->assertFalse($config->authRequired, 'anonymous flow must skip SMTP-AUTH');
            },
        );

        $this->invokeDispatch($this->service($this->centralWithStalwartUrl(), $relay));
    }

    public function testAnonymousAuthRejectDiagnosticsPointsToTrustedIpRunbook(): void {
        $relay = $this->createMock(SmtpMailTestRelay::class);
        $relay->expects($this->once())->method('send')->willReturnCallback(static function (): void {
            throw new MailTestRelayException(
                '530 5.7.0 Authentication required',
                MailTestRelayException::STAGE_AUTH,
            );
        });

        try {
            $this->invokeDispatch($this->service($this->centralWithStalwartUrl(), $relay));
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('trusted-relay list', $e->getMessage());
            $this->assertStringContainsString('anonymous / trusted-IP as postmaster@kunde.example.org', $e->getMessage());
            $this->assertStringContainsString('souvera_central.stalwart_mailtest_user', $e->getMessage());
        }
    }

    public function testMissingStalwartUrlFailsWithConfigStage(): void {
        $relay = $this->createMock(SmtpMailTestRelay::class);
        $relay->expects($this->never())->method('send');

        try {
            $this->invokeDispatch($this->service([], $relay));
            $this->fail('expected MailTestRelayException');
        } catch (MailTestRelayException $e) {
            $this->assertSame(MailTestRelayException::STAGE_CONFIG, $e->stage);
            $this->assertStringContainsString('souvera_central.stalwart_api_url', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Escape hatch: static config credentials → SMTP-AUTH
    // ------------------------------------------------------------------

    public function testStaticCredentialsOptIntoSmtpAuth(): void {
        $relay = $this->createMock(SmtpMailTestRelay::class);
        $relay->expects($this->once())->method('send')->willReturnCallback(
            function (MailTestRelayConfig $config, string $fromAddress): void {
                $this->assertSame('mailtest@kunde.example.org', $fromAddress);
                $this->assertSame('mailtest@kunde.example.org', $config->smtpUser);
                $this->assertSame('ldap-pass-123', $config->smtpPassword);
                $this->assertTrue($config->authRequired);
            },
        );

        $this->invokeDispatch($this->service(
            $this->centralWithStalwartUrl([
                'stalwart_mailtest_user'     => 'MailTest@kunde.example.org', // case-normalised
                'stalwart_mailtest_password' => 'ldap-pass-123',
            ]),
            $relay,
        ));
    }

    public function testStaticUserWithoutPasswordFallsBackToAnonymous(): void {
        // Partial config must NOT be treated as "SMTP-AUTH required" –
        // that would silently fail with a very confusing error message.
        // We drop to the anonymous default instead.
        $relay = $this->createMock(SmtpMailTestRelay::class);
        $relay->expects($this->once())->method('send')->willReturnCallback(
            function (MailTestRelayConfig $config): void {
                $this->assertFalse($config->authRequired);
                $this->assertSame('', $config->smtpUser);
            },
        );

        $this->invokeDispatch($this->service(
            $this->centralWithStalwartUrl([
                'stalwart_mailtest_user' => 'mailtest@kunde.example.org',
                // stalwart_mailtest_password intentionally missing
            ]),
            $relay,
        ));
    }

    public function testStaticCredentialsAuthFailureNamesConfigKeys(): void {
        $relay = $this->createMock(SmtpMailTestRelay::class);
        $relay->expects($this->once())->method('send')->willReturnCallback(static function (): void {
            throw new MailTestRelayException('535 auth failed', MailTestRelayException::STAGE_AUTH);
        });

        try {
            $this->invokeDispatch($this->service(
                $this->centralWithStalwartUrl([
                    'stalwart_mailtest_user'     => 'mailtest@kunde.example.org',
                    'stalwart_mailtest_password' => 'wrong',
                ]),
                $relay,
            ));
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('stalwart_mailtest_user', $e->getMessage());
            $this->assertStringContainsString('stalwart_mailtest_password', $e->getMessage());
            $this->assertStringContainsString('SMTP-AUTH as mailtest@kunde.example.org', $e->getMessage());
        }
    }

    public function testStaticCredentialsMustBelongToWorkspaceDomain(): void {
        $relay = $this->createMock(SmtpMailTestRelay::class);
        $relay->expects($this->never())->method('send');

        try {
            $this->invokeDispatch($this->service(
                $this->centralWithStalwartUrl([
                    'stalwart_mailtest_user'     => 'mailtest@andere-domain.tld',
                    'stalwart_mailtest_password' => 'x',
                ]),
                $relay,
            ));
            $this->fail('expected MailTestRelayException');
        } catch (MailTestRelayException $e) {
            $this->assertSame(MailTestRelayException::STAGE_CONFIG, $e->stage);
            $this->assertStringContainsString('does not belong to workspace domain', $e->getMessage());
        }
    }
}
