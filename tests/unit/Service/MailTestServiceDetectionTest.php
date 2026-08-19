<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit\Service;

use OCA\SouveraShield\Db\DmarcDomain;
use OCA\SouveraShield\Db\MailTest;
use OCA\SouveraShield\Db\MailTestMapper;
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
 * v3.6.0: a mail-test that reaches a final state (completed / expired)
 * must trigger the incident detection immediately via AnalysisRunner –
 * a pending test must not.
 */
class MailTestServiceDetectionTest extends TestCase {

    use L10NTestHelper;

    private function service(ProviderToolsClient $provider, AnalysisRunner $runner): MailTestService {
        $mapper = $this->createMock(MailTestMapper::class);
        $mapper->method('update')->willReturnArgument(0);

        $domain = new DmarcDomain();
        $domain->setDomain('kunde.example.org');
        $managed = $this->createMock(ManagedDomainService::class);
        $managed->method('getOrCreate')->willReturn($domain);

        return new MailTestService(
            $provider,
            $mapper,
            new SouveraCentralConfig($this->createMock(IConfig::class)),
            $this->createMock(SmtpMailTestRelay::class),
            $this->createMock(IAppConfig::class),
            $managed,
            $runner,
            $this->createMock(LoggerInterface::class),
            $this->l10nFactory(),
        );
    }

    private function sentTest(): MailTest {
        $entity = new MailTest();
        $entity->setStatus(MailTest::STATUS_SENT);
        $entity->setTestId('T-1');
        $entity->setTestEmail('x@chk.example.net');
        $entity->setCreatedAt(time() - 60);
        return $entity;
    }

    public function testCompletedResultTriggersIncidentDetection(): void {
        $provider = $this->createMock(ProviderToolsClient::class);
        $provider->method('getMailTest')->willReturn([
            'status' => 'received',
            'score'  => 4.5,
            'analysis' => ['dkim' => ['result' => 'fail']],
        ]);
        $runner = $this->createMock(AnalysisRunner::class);
        $runner->expects($this->once())->method('run');

        $result = $this->service($provider, $runner)->refreshResult($this->sentTest());
        $this->assertSame(MailTest::STATUS_COMPLETED, $result->getStatus());
        $this->assertSame('fail', $result->getDkimResult());
    }

    public function testExpiredResultTriggersIncidentDetection(): void {
        $provider = $this->createMock(ProviderToolsClient::class);
        $provider->method('getMailTest')->willReturn(['status' => 'expired']);
        $runner = $this->createMock(AnalysisRunner::class);
        $runner->expects($this->once())->method('run');

        $result = $this->service($provider, $runner)->refreshResult($this->sentTest());
        $this->assertSame(MailTest::STATUS_ERROR, $result->getStatus());
    }

    public function testPendingResultDoesNotTriggerDetection(): void {
        $provider = $this->createMock(ProviderToolsClient::class);
        $provider->method('getMailTest')->willReturn(['status' => 'pending']);
        $runner = $this->createMock(AnalysisRunner::class);
        $runner->expects($this->never())->method('run');

        $result = $this->service($provider, $runner)->refreshResult($this->sentTest());
        $this->assertSame(MailTest::STATUS_SENT, $result->getStatus());
    }
}
