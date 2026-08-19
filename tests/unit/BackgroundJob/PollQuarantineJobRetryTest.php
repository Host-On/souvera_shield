<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit\BackgroundJob;

use OCA\SouveraShield\BackgroundJob\PollQuarantineJob;
use OCA\SouveraShield\Service\CentralSettings;
use OCA\SouveraShield\Service\PMGClient;
use OCA\SouveraShield\Service\PMGException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the retry semantics of PollQuarantineJob::fetchQuarantineWithRetry.
 *
 * The private method is invoked through reflection so we can assert the
 * exact number of PMG hits without spinning up a live PMG.
 */
class PollQuarantineJobRetryTest extends TestCase {

    private function makeJob(PMGClient $pmg, LoggerInterface $logger): PollQuarantineJob {
        return new PollQuarantineJob(
            $this->createMock(ITimeFactory::class),
            $this->createMock(IUserManager::class),
            $this->createMock(IConfig::class),
            $this->createMock(INotificationManager::class),
            $pmg,
            $this->createMock(CentralSettings::class),
            $logger,
        );
    }

    private function invokeFetch(PollQuarantineJob $job, IUser $user, string $email): mixed {
        $ref = new \ReflectionMethod($job, 'fetchQuarantineWithRetry');
        return $ref->invoke($job, $user, $email);
    }

    public function testSucceedsOnFirstAttempt(): void {
        $pmg = $this->createMock(PMGClient::class);
        $pmg->expects($this->once())
            ->method('getSpamQuarantine')
            ->with('a@example.com')
            ->willReturn(['data' => ['msg1']]);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $job = $this->makeJob($pmg, $this->createMock(LoggerInterface::class));
        $result = $this->invokeFetch($job, $user, 'a@example.com');

        $this->assertSame(['data' => ['msg1']], $result);
    }

    public function testRetriesFourTimesOnTransientErrorsAndThenReturnsNull(): void {
        $pmg = $this->createMock(PMGClient::class);
        // Attempts: initial + 3 retries = 4 total
        $pmg->expects($this->exactly(4))
            ->method('getSpamQuarantine')
            ->willThrowException(new PMGException('boom', 502));

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $job = $this->makeJob($pmg, $this->createMock(LoggerInterface::class));

        // Speed up the test by patching the RETRY_BACKOFFS_US const via ref
        // is not possible – but the pattern's max wait is 5 s + 1 s + 200 ms.
        // Instead we just tolerate ~6 s in CI; the test still measures
        // retry *count*, which is the invariant we care about.
        $result = $this->invokeFetch($job, $user, 'a@example.com');

        $this->assertNull($result);
    }

    public function testDoesNotRetryOnPermanentClientError(): void {
        $pmg = $this->createMock(PMGClient::class);
        // 401 must not trigger retries.
        $pmg->expects($this->once())
            ->method('getSpamQuarantine')
            ->willThrowException(new PMGException('unauthorized', 401));

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $job = $this->makeJob($pmg, $this->createMock(LoggerInterface::class));
        $result = $this->invokeFetch($job, $user, 'a@example.com');

        $this->assertNull($result);
    }

    public function testRecoversAfterOneTransientError(): void {
        $pmg = $this->createMock(PMGClient::class);
        $pmg->expects($this->exactly(2))
            ->method('getSpamQuarantine')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new PMGException('boom', 503)),
                ['data' => ['msg1']],
            );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $job = $this->makeJob($pmg, $this->createMock(LoggerInterface::class));
        $result = $this->invokeFetch($job, $user, 'a@example.com');

        $this->assertSame(['data' => ['msg1']], $result);
    }
}
