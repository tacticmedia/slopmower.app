<?php

declare(strict_types=1);

namespace App\Tests\Functional\Source;

use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\RepositoryUnavailableReason;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RepositoryId;
use App\Domain\Repository\SyncState;
use App\Git\GitReader;
use App\Git\RepositoryLock;
use App\Message\FetchRepositoryJob;
use App\Tests\Support\Clock\SleepHookClock;
use App\Tests\Support\Database\LockStores;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Messenger\Envelope;

/**
 * A lock held in its own database session stands for a fetch in another process.
 */
final class FetchSingleFlightTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use EnvironmentVariables;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewDataFixtures;
    use TemporaryDirectories;

    private GitRepositoryBuilder $source;

    private RepositoryId $repositoryId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        self::enterNewTenant();
        $this->repositoryId = self::connect()->getId();
        self::runQueuedMessages();
    }

    #[TestDox('A fetch requested during a running fetch runs once afterwards and never in parallel')]
    public function testRequestDuringRunningFetchRunsOnceAfterwards(): void
    {
        $newHead = $this->source->commit(['later.txt' => "Later\n"]);
        $runningFetch = self::lockInAnotherSession($this->repositoryId);
        self::fetchRepository()->request($this->repositoryId);
        $clock = new SleepHookClock(function () use ($runningFetch, $newHead): void {
            self::assertFalse($this->hasCommit($newHead), 'The queued fetch ran in parallel.');
            $runningFetch->release();
        });
        Clock::set($clock);

        self::runQueuedMessages();

        self::assertSame(1, $clock->sleeps());
        self::assertTrue($this->hasCommit($newHead));
        self::assertSame(1, self::sentFetchJobs());
        self::assertSame(SyncState::Idle, self::freshRepository($this->repositoryId)->getSyncState());
    }

    #[TestDox('A second asynchronous request while a fetch is queued dispatches nothing')]
    public function testSecondRequestWhileQueuedDispatchesNothing(): void
    {
        self::fetchRepository()->request($this->repositoryId);
        self::fetchRepository()->request($this->repositoryId);

        $repository = self::freshRepository($this->repositoryId);
        self::assertSame(1, self::sentFetchJobs());
        self::assertSame(SyncState::Fetching, $repository->getSyncState());
        self::assertTrue($repository->isFetchRequested());
    }

    #[TestDox('A synchronous caller waits for a running fetch, then fetches')]
    public function testSynchronousCallerWaitsThenFetches(): void
    {
        $newHead = $this->source->commit(['later.txt' => "Later\n"]);
        $runningFetch = self::lockInAnotherSession($this->repositoryId);
        $clock = new SleepHookClock(function (int $sleeps) use ($runningFetch, $newHead): void {
            if (3 === $sleeps) {
                self::assertFalse($this->hasCommit($newHead), 'The synchronous fetch ran in parallel.');
                $runningFetch->release();
            }
        });
        Clock::set($clock);

        self::fetchRepository()->fetchNow($this->repositoryId);

        self::assertSame(3, $clock->sleeps());
        self::assertTrue($this->hasCommit($newHead));
    }

    #[TestDox('A synchronous caller that misses the 60-second deadline gets repository_unavailable with fetch_failed')]
    public function testSynchronousCallerMissesTheDeadline(): void
    {
        $runningFetch = self::lockInAnotherSession($this->repositoryId);
        $clock = self::mockTime();
        $startSeconds = (float) $clock->now()->format('U.u');

        try {
            self::fetchRepository()->fetchNow($this->repositoryId);
            self::fail('The fetch did not fail.');
        } catch (RepositoryUnavailable $error) {
            self::assertSame(RepositoryUnavailableReason::FetchFailed, $error->details()['reason']);
        } finally {
            $runningFetch->release();
        }

        self::assertEqualsWithDelta(60.0, (float) $clock->now()->format('U.u') - $startSeconds, 0.1);
    }

    #[TestDox('A failed job leaves fetchRequested false, so the next request queues a fetch')]
    public function testFailedJobLeavesTheFlagCleared(): void
    {
        self::fetchRepository()->request($this->repositoryId);
        new Filesystem()->remove("{$this->source->path}/.git");

        self::runQueuedMessages();

        $repository = self::freshRepository($this->repositoryId);
        self::assertFalse($repository->isFetchRequested());
        self::assertSame(SyncState::Idle, $repository->getSyncState());
        self::assertNotNull($repository->getLastError());

        self::fetchRepository()->request($this->repositoryId);

        self::assertSame(2, self::sentFetchJobs());
    }

    private function hasCommit(string $commit): bool
    {
        $gitDir = self::clonePath(self::freshRepository($this->repositoryId));

        return self::getContainer()->get(GitReader::class)->hasCommit($gitDir, CommitSha::fromString($commit));
    }

    private static function lockInAnotherSession(RepositoryId $repositoryId): LockInterface
    {
        $lock = new RepositoryLock(new LockFactory(LockStores::inAnotherSession(self::getContainer())), new NativeClock())->create($repositoryId);
        self::assertTrue($lock->acquire());

        return $lock;
    }

    private static function sentFetchJobs(): int
    {
        return count(array_filter(self::asyncTransport()->getSent(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof FetchRepositoryJob));
    }
}
