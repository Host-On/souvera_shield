<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit\Service\Reputation;

use OCA\SouveraShield\Db\DmarcDomain;
use OCA\SouveraShield\Db\MailTest;
use OCA\SouveraShield\Db\RepIncident;
use OCA\SouveraShield\Db\RepIncidentMapper;
use OCA\SouveraShield\Service\Reputation\IncidentService;
use OCA\SouveraShield\Tests\Unit\L10NTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Exercises the incident lifecycle with an in-memory mapper double:
 * raise → dedupe/update → auto-resolve → reopen, plus the measures log.
 */
class IncidentServiceTest extends TestCase {

    use L10NTestHelper;

    /** @var array<string,RepIncident> keyed by dedupe key */
    private array $store = [];

    private function mapper(): RepIncidentMapper {
        $mapper = $this->createMock(RepIncidentMapper::class);
        $mapper->method('findByDedupeKey')->willReturnCallback(
            fn(string $key) => $this->store[$key] ?? null,
        );
        $mapper->method('insert')->willReturnCallback(function (RepIncident $i) {
            $i->setId(count($this->store) + 1);
            $this->store[$i->getDedupeKey()] = $i;
            return $i;
        });
        $mapper->method('update')->willReturnCallback(function (RepIncident $i) {
            $this->store[$i->getDedupeKey()] = $i;
            return $i;
        });
        $mapper->method('findOpen')->willReturnCallback(
            fn() => array_values(array_filter(
                $this->store,
                static fn(RepIncident $i) => $i->getStatus() === RepIncident::STATUS_OPEN,
            )),
        );
        return $mapper;
    }

    private function service(): IncidentService {
        return new IncidentService($this->mapper(), $this->createMock(LoggerInterface::class), $this->l10nFactory());
    }

    private function domain(): DmarcDomain {
        $d = new DmarcDomain();
        $d->setId(1);
        $d->setDomain('kunde.example.org');
        return $d;
    }

    private function checksWith(array $checks): array {
        return ['generated_at' => time(), 'checks' => $checks];
    }

    public function testFailedBlacklistCheckRaisesCriticalIncident(): void {
        $svc = $this->service();
        $checks = $this->checksWith([
            ['id' => 'blacklist_ip', 'status' => 'fail', 'observed' => [
                'target' => '203.0.113.5', 'listedCount' => 1, 'totalChecked' => 120,
                'listed' => [['name' => 'zen.spamhaus.org', 'category' => 'critical']],
            ]],
        ]);
        $summary = $svc->runDetection($this->domain(), $checks, [], []);

        $this->assertSame(1, $summary['raised']);
        $this->assertSame(1, $summary['open']);
        $incident = $this->store['infra:blacklist_ip'];
        $this->assertSame(RepIncident::SEVERITY_CRITICAL, $incident->getSeverity());
        $this->assertSame(RepIncident::CATEGORY_BLACKLIST, $incident->getCategory());
        $this->assertStringContainsString('zen.spamhaus.org', (string)$incident->getDescription());
        $this->assertStringContainsString('delisting', (string)$incident->getRecommendation());
        $measures = $incident->measuresList();
        $this->assertSame('detected', $measures[0]['action']);
    }

    public function testLowDmarcPassRateRaisesAuthIncident(): void {
        $svc = $this->service();
        $insights = ['stats' => ['totalMessages' => 200, 'dkimPassRate' => 30, 'spfPassRate' => 42], 'anomalies' => []];
        $summary = $svc->runDetection($this->domain(), $this->checksWith([]), $insights, []);

        $this->assertSame(1, $summary['raised']);
        $incident = $this->store['auth:dmarc-passrate'];
        $this->assertSame(RepIncident::SEVERITY_CRITICAL, $incident->getSeverity());
        $this->assertStringContainsString('42', $incident->getTitle());
    }

    public function testAnomalySpikeRaisesCompromiseWarning(): void {
        $svc = $this->service();
        $insights = ['stats' => [], 'anomalies' => [
            ['type' => 'volume_spike', 'day' => '2026-06-11', 'messages' => 5000, 'baseline' => 40],
        ]];
        $svc->runDetection($this->domain(), $this->checksWith([]), $insights, []);

        $incident = $this->store['anomaly:spike:2026-06-11'];
        $this->assertSame(RepIncident::CATEGORY_ANOMALY, $incident->getCategory());
        $this->assertStringContainsString('compromised account', (string)$incident->getDescription());
    }

    public function testFailedDispatchRaisesMailTestIncidentWithDiagnosis(): void {
        $svc = $this->service();
        $failed = new MailTest();
        $failed->setStatus(MailTest::STATUS_ERROR);
        $failed->setTestId('t9');
        $failed->setCreatedAt(time());
        $failed->setErrorMessage('Mail dispatch failed: … [Stage: rcpt-to] Stalwart rejects external recipient …');

        $svc->runDetection($this->domain(), $this->checksWith([]), [], [$failed]);
        $incident = $this->store['mailtest:dispatch'];
        $this->assertStringContainsString('rcpt-to', (string)$incident->getDescription());
    }

    public function testRedetectionUpdatesInsteadOfDuplicating(): void {
        $svc = $this->service();
        $checks = $this->checksWith([
            ['id' => 'ptr', 'status' => 'fail', 'observed' => ['ip' => '203.0.113.5', 'ptr' => null]],
        ]);
        $first = $svc->runDetection($this->domain(), $checks, [], []);
        $second = $svc->runDetection($this->domain(), $checks, [], []);

        $this->assertSame(1, $first['raised']);
        $this->assertSame(0, $second['raised']);
        $this->assertSame(1, $second['updated']);
        $this->assertCount(1, $this->store);
    }

    public function testClearedConditionAutoResolvesAndReopensOnReturn(): void {
        $svc = $this->service();
        $failing = $this->checksWith([
            ['id' => 'ptr', 'status' => 'fail', 'observed' => ['ip' => '203.0.113.5', 'ptr' => null]],
        ]);
        $healthy = $this->checksWith([
            ['id' => 'ptr', 'status' => 'ok', 'observed' => ['ip' => '203.0.113.5', 'ptr' => 'mail.kunde.example.org']],
        ]);

        $svc->runDetection($this->domain(), $failing, [], []);
        $resolved = $svc->runDetection($this->domain(), $healthy, [], []);
        $this->assertSame(1, $resolved['auto_resolved']);

        $incident = $this->store['infra:ptr'];
        $this->assertSame(RepIncident::STATUS_RESOLVED, $incident->getStatus());
        $actions = array_column($incident->measuresList(), 'action');
        $this->assertContains('auto_resolved', $actions);

        // Condition returns → the same row is reopened, history preserved.
        $reraised = $svc->runDetection($this->domain(), $failing, [], []);
        $this->assertSame(1, $reraised['raised']);
        $this->assertSame(RepIncident::STATUS_OPEN, $this->store['infra:ptr']->getStatus());
        $actions = array_column($this->store['infra:ptr']->measuresList(), 'action');
        $this->assertContains('reopened', $actions);
        $this->assertCount(1, $this->store);
    }

    public function testManualResolveAppendsMeasure(): void {
        $mapper = $this->mapper();
        $mapper->method('findById')->willReturnCallback(function (int $id) {
            foreach ($this->store as $i) {
                if ((int)$i->getId() === $id) {
                    return $i;
                }
            }
            throw new \OCP\AppFramework\Db\DoesNotExistException('nope');
        });
        $svc = new IncidentService($mapper, $this->createMock(LoggerInterface::class), $this->l10nFactory());

        $checks = $this->checksWith([
            ['id' => 'dkim', 'status' => 'fail', 'observed' => ['result' => 'fail']],
        ]);
        $svc->runDetection($this->domain(), $checks, [], []);
        $id = (int)$this->store['infra:dkim']->getId();

        $resolved = $svc->resolve($id, 'admin');
        $this->assertSame(RepIncident::STATUS_RESOLVED, $resolved->getStatus());
        $this->assertSame('admin', $resolved->getResolvedBy());
        $actions = array_column($resolved->measuresList(), 'action');
        $this->assertContains('resolved', $actions);
    }
}
