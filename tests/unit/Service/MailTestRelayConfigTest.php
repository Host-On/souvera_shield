<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit\Service;

use OCA\SouveraShield\Service\MailTestRelayConfig;
use PHPUnit\Framework\TestCase;

/**
 * Locks down validation of the mail-test relay config (v3.5.0+):
 *   • reads Stalwart coordinates from souvera_central.stalwart_api_url
 *     + optional souvera_central.stalwart_smtp_port
 *   • extracts the host from URLs, "host:port" strings and plain hostnames
 *   • defaults the port to 587 (submission with SMTP-AUTH as the
 *     auto-provisioned postmaster mailbox)
 *   • Port 465 → implicit TLS
 */
class MailTestRelayConfigTest extends TestCase {

    private const USER = 'postmaster@customer.example.com';
    private const PASS = 's3cr3t-mailbox-pass';

    public function testFromStalwartHttpsUrlWithoutPortOverride(): void {
        $cfg = MailTestRelayConfig::fromStalwart('https://mail.souvera.eu:8080', null, self::USER, self::PASS);
        $this->assertNotNull($cfg);
        $this->assertSame('mail.souvera.eu', $cfg->smtpHost);
        $this->assertSame(587, $cfg->smtpPort);
        $this->assertSame(self::USER, $cfg->smtpUser);
        $this->assertSame(self::PASS, $cfg->smtpPassword);
        $this->assertTrue($cfg->authRequired);
        $this->assertSame('none', $cfg->securityMode);
        $this->assertFalse($cfg->usesImplicitTls());
    }

    public function testFromStalwartPortOverrideAsInt(): void {
        $cfg = MailTestRelayConfig::fromStalwart('https://mail.souvera.eu', 465, self::USER, self::PASS);
        $this->assertNotNull($cfg);
        $this->assertSame(465, $cfg->smtpPort);
        $this->assertSame('ssl', $cfg->securityMode);
        $this->assertTrue($cfg->usesImplicitTls());
    }

    public function testFromStalwartPortOverrideAsString(): void {
        $cfg = MailTestRelayConfig::fromStalwart('https://mail.souvera.eu', '25', self::USER, self::PASS);
        $this->assertNotNull($cfg);
        $this->assertSame(25, $cfg->smtpPort);
        $this->assertSame('none', $cfg->securityMode);
    }

    public function testFromStalwartPlainHostname(): void {
        $cfg = MailTestRelayConfig::fromStalwart('mail.internal.local', null, self::USER, self::PASS);
        $this->assertNotNull($cfg);
        $this->assertSame('mail.internal.local', $cfg->smtpHost);
        $this->assertSame(587, $cfg->smtpPort);
    }

    public function testFromStalwartHostWithColonPort(): void {
        $cfg = MailTestRelayConfig::fromStalwart('10.0.0.20:8080', null, self::USER, self::PASS);
        $this->assertNotNull($cfg);
        $this->assertSame('10.0.0.20', $cfg->smtpHost);
        $this->assertSame(587, $cfg->smtpPort);
    }

    public function testMissingUrlYieldsNull(): void {
        $this->assertNull(MailTestRelayConfig::fromStalwart(null, null, self::USER, self::PASS));
        $this->assertNull(MailTestRelayConfig::fromStalwart('', null, self::USER, self::PASS));
        $this->assertNull(MailTestRelayConfig::fromStalwart('   ', null, self::USER, self::PASS));
    }

    public function testInvalidPortOverrideFallsBackTo587(): void {
        $cfg = MailTestRelayConfig::fromStalwart('mail.souvera.eu', 'nonsense', self::USER, self::PASS);
        $this->assertNotNull($cfg);
        $this->assertSame(587, $cfg->smtpPort);
    }

    public function testZeroPortOverrideFallsBackTo587(): void {
        $cfg = MailTestRelayConfig::fromStalwart('mail.souvera.eu', 0, self::USER, self::PASS);
        $this->assertNotNull($cfg);
        $this->assertSame(587, $cfg->smtpPort);
    }

    public function testAuthRequiredFollowsUser(): void {
        $withAuth = MailTestRelayConfig::fromStalwart('mail.souvera.eu', null, self::USER, self::PASS);
        $this->assertNotNull($withAuth);
        $this->assertTrue($withAuth->authRequired);

        $withoutAuth = MailTestRelayConfig::fromStalwart('mail.souvera.eu');
        $this->assertNotNull($withoutAuth);
        $this->assertFalse($withoutAuth->authRequired);
        $this->assertSame('', $withoutAuth->smtpUser);
    }

    public function testSmtpHostOverrideWinsOverApiUrl(): void {
        $cfg = MailTestRelayConfig::fromStalwart('https://proxy.example.com:8080', null, self::USER, self::PASS, '10.0.0.20');
        $this->assertNotNull($cfg);
        $this->assertSame('10.0.0.20', $cfg->smtpHost);
        $this->assertSame(587, $cfg->smtpPort);
    }

    public function testSmtpHostOverrideToleratesUrlAndPortSyntax(): void {
        $cfg = MailTestRelayConfig::fromStalwart('https://proxy.example.com', null, self::USER, self::PASS, 'ssl://mail.internal.local:465');
        $this->assertNotNull($cfg);
        $this->assertSame('mail.internal.local', $cfg->smtpHost);
    }

    public function testSmtpHostOverrideWorksWithoutApiUrl(): void {
        $cfg = MailTestRelayConfig::fromStalwart(null, 465, self::USER, self::PASS, 'mail.internal.local');
        $this->assertNotNull($cfg);
        $this->assertSame('mail.internal.local', $cfg->smtpHost);
        $this->assertTrue($cfg->usesImplicitTls());
    }

    public function testBlankHostOverrideFallsBackToApiUrl(): void {
        $cfg = MailTestRelayConfig::fromStalwart('https://mail.souvera.eu', null, self::USER, self::PASS, '   ');
        $this->assertNotNull($cfg);
        $this->assertSame('mail.souvera.eu', $cfg->smtpHost);
    }
}
