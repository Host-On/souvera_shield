<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit\Service\Reputation;

use OCA\SouveraShield\Service\Reputation\DeliverabilityCheckService;
use OCA\SouveraShield\Service\Reputation\DnsInspector;
use OCA\SouveraShield\Service\Reputation\SmtpProbe;
use PHPUnit\Framework\TestCase;

/**
 * v3.8.1 regression – the HELO-identity / STARTTLS check must probe the
 * server as *external MTAs* see it (PTR of the outbound IP, then MX).
 *
 * Bug pre-v3.8.1: the probe hit `stalwart_api_url` first (internal
 * management endpoint, e.g. `mx.example.com`) and reported its banner
 * as the "external" HELO identity – misleading when the outbound
 * mail-facing hostname (e.g. `shield01.souvera.email`) differs.
 */
class DeliverabilityHeloProbeTest extends TestCase {

    private function invokeHelo(
        DeliverabilityCheckService $svc,
        string $domain,
        ?string $ip,
        ?string $ptrHost,
    ): array {
        $ref = new \ReflectionMethod($svc, 'checkHeloAndTls');
        $ref->setAccessible(true);
        /** @var array<string,mixed> $result */
        $result = $ref->invoke($svc, $domain, $ip, $ptrHost, []);
        return $result;
    }

    private function service(SmtpProbe $probe, DnsInspector $dns): DeliverabilityCheckService {
        return new DeliverabilityCheckService(
            $dns,
            $probe,
            $this->createMock(\OCA\SouveraShield\Service\ProviderToolsClient::class),
            $this->createMock(\OCA\SouveraShield\Db\MailTestMapper::class),
            $this->createMock(\OCA\SouveraShield\Service\SouveraCentralConfig::class),
            $this->createMock(\OCP\IAppConfig::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
        );
    }

    public function testProbesPtrHostFirstNotStalwartApiUrl(): void {
        $probe = $this->createMock(SmtpProbe::class);
        $probe->expects($this->once())
            ->method('probe')
            ->with('shield01.souvera.email', 25)
            ->willReturn([
                'reachable'   => true,
                'banner_host' => 'shield01.souvera.email',
                'starttls'    => true,
                'error'       => null,
            ]);
        $dns = $this->createMock(DnsInspector::class);
        // mxRecords may be queried to build the fallback list but is
        // never *probed* when the PTR target already succeeded.
        $dns->method('mxRecords')->willReturn(['shield01.souvera.email']);

        $result = $this->invokeHelo(
            $this->service($probe, $dns),
            'kunde.example.org',
            '203.0.113.10',
            'shield01.souvera.email',
        );

        $this->assertSame('helo_tls', $result['id']);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('shield01.souvera.email', $result['observed']['probed_host']);
        $this->assertSame('ptr', $result['observed']['probe_kind']);
        $this->assertSame('shield01.souvera.email', $result['observed']['banner_host']);
        $this->assertTrue($result['observed']['starttls']);
    }

    public function testFallsBackToMxWhenPtrMissing(): void {
        $probe = $this->createMock(SmtpProbe::class);
        $probe->expects($this->once())
            ->method('probe')
            ->with('mx1.kunde.example.org', 25)
            ->willReturn([
                'reachable'   => true,
                'banner_host' => 'mx1.kunde.example.org',
                'starttls'    => true,
                'error'       => null,
            ]);
        $dns = $this->createMock(DnsInspector::class);
        $dns->expects($this->once())
            ->method('mxRecords')
            ->with('kunde.example.org')
            ->willReturn(['mx1.kunde.example.org']);

        $result = $this->invokeHelo(
            $this->service($probe, $dns),
            'kunde.example.org',
            '203.0.113.10',
            null, // no PTR available
        );

        $this->assertSame('ok', $result['status']);
        $this->assertSame('mx', $result['observed']['probe_kind']);
    }

    public function testBannerPtrMismatchIsFlaggedAsWarn(): void {
        // Simulates the exact user-reported bug scenario, now caught:
        // PTR = shield01.souvera.email but the probed :25 responds with
        // banner "mx.example.com". After v3.8.1 we probe the PTR
        // directly, so this only fires when Stalwart really does answer
        // with a divergent HELO on the external endpoint.
        $probe = $this->createMock(SmtpProbe::class);
        $probe->method('probe')->willReturn([
            'reachable'   => true,
            'banner_host' => 'mx.example.com',
            'starttls'    => true,
            'error'       => null,
        ]);
        $dns = $this->createMock(DnsInspector::class);

        $result = $this->invokeHelo(
            $this->service($probe, $dns),
            'kunde.example.org',
            '203.0.113.10',
            'shield01.souvera.email',
        );

        $this->assertSame('warn', $result['status']);
        $this->assertSame('banner_ptr_mismatch', $result['observed']['issue']);
        $this->assertSame('shield01.souvera.email', $result['observed']['probed_host']);
        $this->assertSame('mx.example.com', $result['observed']['banner_host']);
    }

    public function testNoStarttlsIsHardFail(): void {
        $probe = $this->createMock(SmtpProbe::class);
        $probe->method('probe')->willReturn([
            'reachable'   => true,
            'banner_host' => 'shield01.souvera.email',
            'starttls'    => false,
            'error'       => null,
        ]);
        $dns = $this->createMock(DnsInspector::class);

        $result = $this->invokeHelo(
            $this->service($probe, $dns),
            'kunde.example.org',
            '203.0.113.10',
            'shield01.souvera.email',
        );

        $this->assertSame('fail', $result['status']);
        $this->assertSame('no_starttls', $result['observed']['issue']);
    }

    public function testNoDataWhenNoTargetsAvailable(): void {
        $probe = $this->createMock(SmtpProbe::class);
        $probe->expects($this->never())->method('probe');
        $dns = $this->createMock(DnsInspector::class);
        $dns->method('mxRecords')->willReturn([]);

        $result = $this->invokeHelo(
            $this->service($probe, $dns),
            'kunde.example.org',
            null,
            null,
        );

        $this->assertSame('nodata', $result['status']);
        $this->assertSame('no_probe_target', $result['observed']['reason']);
    }

    public function testSkipsMxWhenIdenticalToPtr(): void {
        // PTR and MX point to the same host: probe only once.
        $probe = $this->createMock(SmtpProbe::class);
        $probe->expects($this->once())
            ->method('probe')
            ->with('shield01.souvera.email', 25)
            ->willReturn([
                'reachable'   => true,
                'banner_host' => 'shield01.souvera.email',
                'starttls'    => true,
                'error'       => null,
            ]);
        $dns = $this->createMock(DnsInspector::class);
        // Both PTR present *and* MX must be queried – de-dup happens on host.
        $dns->method('mxRecords')->willReturn(['shield01.souvera.email']);

        $result = $this->invokeHelo(
            $this->service($probe, $dns),
            'kunde.example.org',
            '203.0.113.10',
            'shield01.souvera.email',
        );
        $this->assertSame('ok', $result['status']);
    }
}
