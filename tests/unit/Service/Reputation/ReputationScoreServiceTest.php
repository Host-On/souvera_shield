<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit\Service\Reputation;

use OCA\SouveraShield\Db\DmarcDomain;
use OCA\SouveraShield\Db\MailTest;
use OCA\SouveraShield\Db\MailTestMapper;
use OCA\SouveraShield\Db\RepIncident;
use OCA\SouveraShield\Db\RepIncidentMapper;
use OCA\SouveraShield\Db\RepSnapshotMapper;
use OCA\SouveraShield\Service\Reputation\ReputationScoreService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReputationScoreServiceTest extends TestCase {

    private function domain(): DmarcDomain {
        $d = new DmarcDomain();
        $d->setId(7);
        $d->setDomain('kunde.example.org');
        return $d;
    }

    private function service(array $openIncidents = [], array $recentTests = []): ReputationScoreService {
        $snapshots = $this->createMock(RepSnapshotMapper::class);
        $incidents = $this->createMock(RepIncidentMapper::class);
        $incidents->method('findOpen')->willReturn($openIncidents);
        $tests = $this->createMock(MailTestMapper::class);
        $tests->method('findRecent')->willReturn($recentTests);
        return new ReputationScoreService(
            $snapshots,
            $incidents,
            $tests,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function checks(array $checks): array {
        return ['generated_at' => time(), 'outbound_ip' => '203.0.113.5', 'ip_source' => 'mx', 'checks' => $checks];
    }

    public function testInsufficientDataYieldsNullScoreNeverFake(): void {
        // Only the incidents component is available – but with no DMARC
        // data, no mail test and no checks the score must NOT pretend to
        // know anything except the incident state.
        $svc = $this->service();
        $result = $svc->compute($this->domain(), [], $this->checks([]));

        // incidents component alone (weight 10) → still a score, but the
        // dmarc/mail_test/blacklist/infrastructure components must be
        // flagged unavailable.
        $byId = [];
        foreach ($result['components'] as $c) {
            $byId[$c['id']] = $c;
        }
        $this->assertFalse($byId['dmarc']['available']);
        $this->assertFalse($byId['mail_test']['available']);
        $this->assertFalse($byId['blacklist']['available']);
        $this->assertFalse($byId['infrastructure']['available']);
        $this->assertTrue($byId['incidents']['available']);
        $this->assertSame(100, $byId['incidents']['score']);
    }

    public function testHealthySetupScoresHigh(): void {
        $test = new MailTest();
        $test->setStatus(MailTest::STATUS_COMPLETED);
        $test->setScore(10.0);
        $test->setTestId('t1');

        $svc = $this->service([], [$test]);
        $stats = ['totalMessages' => 500, 'dkimPassRate' => 100, 'spfPassRate' => 98];
        $checks = $this->checks([
            ['id' => 'spf_record', 'status' => 'ok', 'observed' => []],
            ['id' => 'dmarc_policy', 'status' => 'ok', 'observed' => []],
            ['id' => 'ptr', 'status' => 'ok', 'observed' => []],
            ['id' => 'blacklist_ip', 'status' => 'ok', 'observed' => ['listed' => [], 'listedCount' => 0]],
            ['id' => 'blacklist_domain', 'status' => 'ok', 'observed' => ['listed' => [], 'listedCount' => 0]],
        ]);
        $result = $svc->compute($this->domain(), $stats, $checks);
        $this->assertSame('ok', $result['state']);
        $this->assertGreaterThanOrEqual(95, $result['score']);
    }

    public function testCriticalBlacklistListingDragsScoreDown(): void {
        $svc = $this->service();
        $checks = $this->checks([
            ['id' => 'blacklist_ip', 'status' => 'fail', 'observed' => [
                'listedCount' => 2,
                'listed' => [
                    ['name' => 'zen.spamhaus.org', 'category' => 'critical'],
                    ['name' => 'bl.example.net', 'category' => 'aggressive'],
                ],
            ]],
            ['id' => 'blacklist_domain', 'status' => 'ok', 'observed' => ['listed' => [], 'listedCount' => 0]],
        ]);
        $result = $svc->compute($this->domain(), [], $checks);
        $byId = [];
        foreach ($result['components'] as $c) {
            $byId[$c['id']] = $c;
        }
        // 100 − 40 (critical) − 15 (other) = 45
        $this->assertTrue($byId['blacklist']['available']);
        $this->assertSame(45, $byId['blacklist']['score']);
    }

    public function testOpenIncidentsReduceIncidentComponent(): void {
        $critical = new RepIncident();
        $critical->setSeverity(RepIncident::SEVERITY_CRITICAL);
        $warning = new RepIncident();
        $warning->setSeverity(RepIncident::SEVERITY_WARNING);

        $svc = $this->service([$critical, $warning]);
        $result = $svc->compute($this->domain(), [], $this->checks([]));
        $byId = [];
        foreach ($result['components'] as $c) {
            $byId[$c['id']] = $c;
        }
        // 100 − 30 − 10 = 60
        $this->assertSame(60, $byId['incidents']['score']);
    }

    public function testMailTestScoreIsNormalisedFromTenScale(): void {
        $test = new MailTest();
        $test->setStatus(MailTest::STATUS_COMPLETED);
        $test->setScore(7.5);
        $test->setTestId('t2');

        $svc = $this->service([], [$test]);
        $result = $svc->compute($this->domain(), [], $this->checks([]));
        $byId = [];
        foreach ($result['components'] as $c) {
            $byId[$c['id']] = $c;
        }
        $this->assertTrue($byId['mail_test']['available']);
        $this->assertSame(75, $byId['mail_test']['score']);
    }

    public function testInfoAndNodataChecksAreNotScored(): void {
        $svc = $this->service();
        $checks = $this->checks([
            ['id' => 'spf_record', 'status' => 'ok', 'observed' => []],
            ['id' => 'bimi', 'status' => 'info', 'observed' => []],
            ['id' => 'ptr', 'status' => 'nodata', 'observed' => []],
        ]);
        $result = $svc->compute($this->domain(), [], $checks);
        $byId = [];
        foreach ($result['components'] as $c) {
            $byId[$c['id']] = $c;
        }
        $this->assertSame(1, $byId['infrastructure']['detail']['checks_scored']);
        $this->assertSame(100, $byId['infrastructure']['score']);
    }
}
