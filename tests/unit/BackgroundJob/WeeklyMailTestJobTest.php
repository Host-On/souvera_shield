<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit\BackgroundJob;

use OCA\SouveraShield\BackgroundJob\WeeklyMailTestJob;
use OCA\SouveraShield\Db\DmarcDomain;
use OCA\SouveraShield\Service\MailTestService;
use OCA\SouveraShield\Service\ManagedDomainService;
use OCA\SouveraShield\Service\ProviderToolsClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Locks down the v3.6.0 schedule: the weekly mail-test fires on MONDAY
 * at a random, instance-specific time between 00:01 and 06:00 – exactly
 * once per ISO week.
 */
class WeeklyMailTestJobTest extends TestCase {

    /** @var array<string,string> */
    private array $store = [];

    private function appConfig(): IAppConfig {
        $mock = $this->createMock(IAppConfig::class);
        $mock->method('getValueString')->willReturnCallback(
            fn(string $app, string $key, string $default = '') => $this->store[$key] ?? $default
        );
        $mock->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value) {
                $this->store[$key] = $value;
                return true;
            }
        );
        return $mock;
    }

    private function job(int $now, MailTestService $mailTestService, bool $providerConfigured = true): WeeklyMailTestJob {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($now);

        $provider = $this->createMock(ProviderToolsClient::class);
        $provider->method('isConfigured')->willReturn($providerConfigured);

        $domain = new DmarcDomain();
        $domain->setDomain('kunde.example.org');
        $managed = $this->createMock(ManagedDomainService::class);
        $managed->method('getOrCreate')->willReturn($domain);

        return new WeeklyMailTestJob(
            $time,
            $managed,
            $mailTestService,
            $provider,
            $this->appConfig(),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function invoke(WeeklyMailTestJob $job): void {
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }

    /** 2026-06-15 is a Monday (server TZ = test TZ). */
    private function mondayAt(int $secondsAfterMidnight): int {
        return (int)strtotime('2026-06-15 00:00:00') + $secondsAfterMidnight;
    }

    public function testSkipsOnNonMonday(): void {
        $this->store['weekly_mail_test.slot_seconds'] = '60';
        $svc = $this->createMock(MailTestService::class);
        $svc->expects($this->never())->method('run');
        // Tuesday 03:00
        $this->invoke($this->job((int)strtotime('2026-06-16 03:00:00'), $svc));
    }

    public function testSkipsBeforeInstanceSlot(): void {
        $this->store['weekly_mail_test.slot_seconds'] = '7200'; // 02:00
        $svc = $this->createMock(MailTestService::class);
        $svc->expects($this->never())->method('run');
        $this->invoke($this->job($this->mondayAt(3600), $svc)); // Monday 01:00
    }

    public function testRunsAfterSlotExactlyOncePerWeek(): void {
        $this->store['weekly_mail_test.slot_seconds'] = '7200'; // 02:00
        $svc = $this->createMock(MailTestService::class);
        $svc->expects($this->once())->method('run');
        $this->invoke($this->job($this->mondayAt(3 * 3600), $svc));  // Monday 03:00 → runs
        $this->assertSame('2026-W25', $this->store['weekly_mail_test.last_run_week']);
        $this->invoke($this->job($this->mondayAt(4 * 3600), $svc));  // Monday 04:00 → guarded
    }

    public function testGeneratesPersistentSlotWithinWindow(): void {
        $svc = $this->createMock(MailTestService::class);
        $this->invoke($this->job($this->mondayAt(30), $svc)); // Monday 00:00:30
        $slot = (int)($this->store['weekly_mail_test.slot_seconds'] ?? 0);
        $this->assertGreaterThanOrEqual(60, $slot);        // ≥ 00:01:00
        $this->assertLessThanOrEqual(6 * 3600, $slot);     // ≤ 06:00:00
    }

    public function testSkipsWhenProviderNotConfigured(): void {
        $this->store['weekly_mail_test.slot_seconds'] = '60';
        $svc = $this->createMock(MailTestService::class);
        $svc->expects($this->never())->method('run');
        $this->invoke($this->job($this->mondayAt(3 * 3600), $svc, providerConfigured: false));
    }
}
