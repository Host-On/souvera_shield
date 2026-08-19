<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit\Service;

use OCA\SouveraShield\Service\MailTestRelayConfig;
use OCA\SouveraShield\Service\MailTestRelayException;
use OCA\SouveraShield\Service\SmtpMailTestRelay;
use OCA\SouveraShield\Tests\Unit\L10NTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Locks down the errno-classified connect diagnostics (v3.5.9): a failed
 * TCP connect must name the URL, the OS error and a layer-specific hint
 * (SELinux / firewall / closed port) instead of a generic message.
 *
 * v3.9.0: hints are now in English (via IL10N), tests updated accordingly.
 */
class SmtpMailTestRelayConnectTest extends TestCase {

    use L10NTestHelper;

    private function relay(): SmtpMailTestRelay {
        return new SmtpMailTestRelay($this->createMock(LoggerInterface::class), $this->l10nFactory());
    }

    private function config(string $host, int $port): MailTestRelayConfig {
        return new MailTestRelayConfig(
            smtpHost:     $host,
            smtpPort:     $port,
            smtpUser:     'postmaster@customer.example.com',
            smtpPassword: 'secret',
            authRequired: true,
            securityMode: 'none',
        );
    }

    public function testConnectionRefusedIsClassified(): void {
        try {
            // Port 1 on loopback: nothing listens there → ECONNREFUSED.
            $this->relay()->send(
                $this->config('127.0.0.1', 1),
                'postmaster@customer.example.com',
                'x@chk.example.net',
                'Souvera Shield',
                'subject',
                'body',
            );
            $this->fail('expected a connect-stage exception');
        } catch (MailTestRelayException $e) {
            $this->assertSame(MailTestRelayException::STAGE_CONNECT, $e->stage);
            $this->assertStringContainsString('tcp://127.0.0.1:1', $e->getMessage());
            $this->assertStringContainsString('errno 111', $e->getMessage());
            $this->assertStringContainsString('Connection refused', $e->getMessage());
            $this->assertStringContainsString('no service is listening', $e->getMessage());
        }
    }

    public function testDescribeConnectFailureClassifiesSelinuxPermissionDenied(): void {
        $ref = new \ReflectionMethod(SmtpMailTestRelay::class, 'describeConnectFailure');
        $ref->setAccessible(true);
        $msg = (string)$ref->invoke($this->relay(), $this->config('10.0.0.20', 587), 13, 'Permission denied');
        $this->assertStringContainsString('tcp://10.0.0.20:587', $msg);
        $this->assertStringContainsString('errno 13', $msg);
        $this->assertStringContainsString('SELinux', $msg);
        $this->assertStringContainsString('httpd_can_sendmail', $msg);
    }

    public function testDescribeConnectFailureClassifiesTimeout(): void {
        $ref = new \ReflectionMethod(SmtpMailTestRelay::class, 'describeConnectFailure');
        $ref->setAccessible(true);
        $msg = (string)$ref->invoke($this->relay(), $this->config('10.0.0.20', 587), 110, 'Connection timed out');
        $this->assertStringContainsString('firewall/routing', $msg);

        // PHP-side timeout: errno 0, empty errstr.
        $msg = (string)$ref->invoke($this->relay(), $this->config('10.0.0.20', 587), 0, '');
        $this->assertStringContainsString('Timeout after', $msg);
        $this->assertStringContainsString('DROP rule', $msg);
    }
}
