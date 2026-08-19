<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit\Service;

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
 * v3.9.0: Diagnostic texts use English source strings + IL10N so the
 * fallback locale (any locale without a translation) shows English.
 * DE + NL translations live in `l10n/*.json`.
 */
class MailTestServiceDiagnosticsTest extends TestCase {

    use L10NTestHelper;

    private MailTestRelayConfig $relay;
    private const FROM = 'postmaster@customer.example.com';
    private const TO   = 'k9gwjs@chk.provider.tools';

    protected function setUp(): void {
        parent::setUp();
        $this->relay = new MailTestRelayConfig(
            smtpHost: 'mail.souvera.eu',
            smtpPort: 587,
            smtpUser: '',
            smtpPassword: '',
            authRequired: false,
            securityMode: 'none',
        );
    }

    private function service(): MailTestService {
        return new MailTestService(
            $this->createMock(ProviderToolsClient::class),
            $this->createMock(MailTestMapper::class),
            new SouveraCentralConfig($this->createMock(IConfig::class)),
            $this->createMock(SmtpMailTestRelay::class),
            $this->createMock(IAppConfig::class),
            $this->createMock(ManagedDomainService::class),
            $this->createMock(AnalysisRunner::class),
            $this->createMock(LoggerInterface::class),
            $this->l10nFactory(),
        );
    }

    private function callInterpret(string $raw, string $stage, ?string $to = null, ?string $from = null): string {
        $svc = $this->service();
        $ref = new \ReflectionMethod($svc, 'interpretMailerFailure');
        $ref->setAccessible(true);
        return (string)$ref->invoke(
            $svc,
            $raw,
            $to ?? self::TO,
            $stage,
            $this->relay,
            $from ?? self::FROM,
        );
    }

    public function testConfigStagePassesRawMessageThrough(): void {
        $msg = $this->callInterpret(
            'Stalwart relay is not configured. Please set souvera_central.stalwart_api_url.',
            MailTestRelayException::STAGE_CONFIG,
        );
        $this->assertStringContainsString('Stalwart relay is not configured', $msg);
        $this->assertStringContainsString('souvera_central.stalwart_api_url', $msg);
    }

    public function testProvisionStageRemovedInV370(): void {
        // STAGE_PROVISION was retired together with StalwartAdminClient
        // in v3.7.0; guard against accidental reintroduction of the constant.
        $this->assertFalse(
            defined(MailTestRelayException::class . '::STAGE_PROVISION'),
            'MailTestRelayException::STAGE_PROVISION must not exist after v3.7.0',
        );
    }

    public function testConnectStageMentionsStalwartAndPort(): void {
        $msg = $this->callInterpret(
            'tcp://mail.souvera.eu:587: Connection refused (errno 111)',
            MailTestRelayException::STAGE_CONNECT,
        );
        $this->assertStringContainsString('not reachable via SMTP', $msg);
        $this->assertStringContainsString('Connection refused (errno 111)', $msg);
        $this->assertStringContainsString('souvera_central.stalwart_smtp_host', $msg);
        $this->assertStringContainsString('souvera_central.stalwart_smtp_port', $msg);
        $this->assertStringContainsString('587', $msg);
        $this->assertStringContainsString('Nextcloud webserver process', $msg);
    }

    public function testStarttlsStageMentionsStalwartCert(): void {
        $msg = $this->callInterpret(
            'STARTTLS handshake failed',
            MailTestRelayException::STAGE_STARTTLS,
        );
        $this->assertStringContainsString('TLS handshake with Stalwart', $msg);
        $this->assertStringContainsString('souvera_central.stalwart_smtp_port', $msg);
    }

    public function testAuthStageDefaultExplainsTrustedIpRunbook(): void {
        // Default flow: anonymous submission. Diagnostic must point to
        // the trusted-IP relay config, not to a credential store.
        $msg = $this->callInterpret(
            '530 5.7.0 Authentication required',
            MailTestRelayException::STAGE_AUTH,
        );
        $this->assertStringContainsString('Stalwart requires SMTP-AUTH', $msg);
        $this->assertStringContainsString('trusted-relay list', $msg);
        $this->assertStringContainsString('souvera_central.stalwart_mailtest_user', $msg);
        $this->assertStringContainsString('anonymous / trusted-IP as ' . self::FROM, $msg);
    }

    public function testAuthStageStaticExplainsCredentialsRunbook(): void {
        // Escape-hatch flow: static config credentials set → different runbook.
        $svc = $this->service();
        $ref = new \ReflectionMethod($svc, 'interpretMailerFailure');
        $ref->setAccessible(true);
        $msg = (string)$ref->invoke(
            $svc,
            '535 5.7.8 Authentication credentials invalid',
            self::TO,
            MailTestRelayException::STAGE_AUTH,
            $this->relay,
            self::FROM,
            true, // staticCredentials
        );
        $this->assertStringContainsString('SMTP-AUTH as ' . self::FROM, $msg);
        $this->assertStringContainsString('credentials from config.php', $msg);
        $this->assertStringContainsString('souvera_central.stalwart_mailtest_user', $msg);
    }

    public function testFromStageMentionsCustomerDomain(): void {
        $msg = $this->callInterpret(
            '501 5.5.4 Sender not local',
            MailTestRelayException::STAGE_FROM,
            null,
            'postmaster@kunde.beispiel.de',
        );
        $this->assertStringContainsString('rejects sender', $msg);
        $this->assertStringContainsString('postmaster@kunde.beispiel.de', $msg);
        $this->assertStringContainsString('"kunde.beispiel.de"', $msg);
        $this->assertStringContainsString('local sending domain', $msg);
    }

    public function testRcptStageDefaultMentionsTrustedIpRelay(): void {
        $msg = $this->callInterpret(
            '550 5.1.2 Relay not allowed',
            MailTestRelayException::STAGE_RCPT,
        );
        $this->assertStringContainsString('rejects external recipient', $msg);
        $this->assertStringContainsString(self::TO, $msg);
        $this->assertStringContainsString('trusted-IP entry', $msg);
        $this->assertStringContainsString('anonymous / trusted-IP as', $msg);
    }

    public function testDataStageMentionsStalwartLog(): void {
        $msg = $this->callInterpret(
            '552 5.6.0 Content rejected',
            MailTestRelayException::STAGE_DATA,
        );
        $this->assertStringContainsString('rejected the message after DATA', $msg);
        $this->assertStringContainsString('Stalwart log', $msg);
    }

    public function testUnknownStageReturnsRelayInfoAndRaw(): void {
        $msg = $this->callInterpret(
            'Something exploded',
            'unrecognised',
        );
        $this->assertStringContainsString('Reputation check', $msg);
        $this->assertStringContainsString('mail.souvera.eu:587', $msg);
        $this->assertStringContainsString('anonymous / trusted-IP as ' . self::FROM, $msg);
        $this->assertStringContainsString('Something exploded', $msg);
    }

    public function testTruncateForDbLimitsLongMessages(): void {
        $svc = $this->service();
        $ref = new \ReflectionMethod($svc, 'truncateForDb');
        $ref->setAccessible(true);

        $short = str_repeat('a', 900);
        $this->assertSame($short, $ref->invoke($svc, $short));

        $long = str_repeat('b', 1500);
        $out = (string)$ref->invoke($svc, $long);
        $this->assertLessThanOrEqual(1000, mb_strlen($out));
        $this->assertStringEndsWith('…', $out);

        $this->assertNull($ref->invoke($svc, null));
    }

    public function testDeriveSenderAddressDefaultsToPostmaster(): void {
        $svc = $this->service();
        $ref = new \ReflectionMethod($svc, 'deriveSenderAddress');
        $ref->setAccessible(true);

        $domain = new \OCA\SouveraShield\Db\DmarcDomain();
        $domain->setDomain('kunde.example.org');

        $domain->setSenderAddress('');
        $this->assertSame('postmaster@kunde.example.org', $ref->invoke($svc, $domain));

        // Foreign-domain override must be rejected (breaks alignment).
        $domain->setSenderAddress('no-reply@fremd.example.net');
        $this->assertSame('postmaster@kunde.example.org', $ref->invoke($svc, $domain));

        // Same-domain override is honoured.
        $domain->setSenderAddress('checks@kunde.example.org');
        $this->assertSame('checks@kunde.example.org', $ref->invoke($svc, $domain));
    }
}
