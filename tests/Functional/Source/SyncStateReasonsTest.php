<?php

declare(strict_types=1);

namespace App\Tests\Functional\Source;

use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Error\RepositoryUnavailableReason;
use App\Domain\Identity\RepositoryId;
use App\Domain\Repository\RepositoryAccess;
use App\Domain\Repository\SyncState;
use App\Entity\Repository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Ulid;

/**
 * The connected repository is `cloning`, and its clone job is queued.
 */
final class SyncStateReasonsTest extends KernelTestCase
{
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
    }

    #[TestDox('A synchronous fetch while the clone is queued gives repository_unavailable with cloning')]
    public function testFetchWhileCloning(): void
    {
        $this->assertUnavailable(RepositoryUnavailableReason::Cloning, fn () => self::fetchRepository()->fetchNow($this->repositoryId));
    }

    #[TestDox('A synchronous fetch after a failed clone gives repository_unavailable with clone_failed')]
    public function testFetchAfterFailedClone(): void
    {
        new Filesystem()->remove("{$this->source->path}/.git");
        self::runQueuedMessages();

        $this->assertUnavailable(RepositoryUnavailableReason::CloneFailed, fn () => self::fetchRepository()->fetchNow($this->repositoryId));
    }

    #[TestDox('A synchronous fetch of a removed repository gives repository_unavailable with removed')]
    public function testFetchOfRemovedRepository(): void
    {
        self::runQueuedMessages();
        $this->markRemoved();

        $this->assertUnavailable(RepositoryUnavailableReason::Removed, fn () => self::fetchRepository()->fetchNow($this->repositoryId));
    }

    #[TestDox('A fetch request for a removed repository gives repository_unavailable with removed')]
    public function testRequestForRemovedRepository(): void
    {
        self::runQueuedMessages();
        $this->markRemoved();

        $this->assertUnavailable(RepositoryUnavailableReason::Removed, fn () => self::fetchRepository()->request($this->repositoryId));
    }

    #[TestDox('A git failure in a synchronous fetch gives repository_unavailable with fetch_failed and sets lastError')]
    public function testGitFailureInSynchronousFetch(): void
    {
        self::runQueuedMessages();
        $this->emptySourceGitDirectory();

        $this->assertUnavailable(RepositoryUnavailableReason::FetchFailed, fn () => self::fetchRepository()->fetchNow($this->repositoryId));

        $repository = self::freshRepository($this->repositoryId);
        self::assertSame(SyncState::Idle, $repository->getSyncState());
        self::assertStringContainsString('does not appear to be a git repository', (string) $repository->getLastError());
    }

    #[TestDox('lastError of a failed fetch has the git subcommand, exit code, and first stderr line, without server paths')]
    public function testLastErrorHasNoServerPath(): void
    {
        self::runQueuedMessages();
        $this->emptySourceGitDirectory();

        self::fetchRepository()->request($this->repositoryId);
        self::runQueuedMessages();

        self::assertSame("git fetch exited with 128: fatal: '[redacted]' does not appear to be a git repository", self::freshRepository($this->repositoryId)->getLastError());
    }

    /**
     * The directory stays a candidate, so the fetch reaches git.
     */
    private function emptySourceGitDirectory(): void
    {
        $filesystem = new Filesystem();
        $filesystem->remove("{$this->source->path}/.git");
        $filesystem->mkdir("{$this->source->path}/.git");
    }

    private function assertUnavailable(RepositoryUnavailableReason $expectedReason, callable $sync): void
    {
        try {
            $sync();
        } catch (RepositoryUnavailable $error) {
            self::assertSame($expectedReason, $error->details()['reason']);

            return;
        }

        self::fail('No RepositoryUnavailable was thrown.');
    }

    /**
     * As M33 does when the GitHub App loses access.
     */
    private function markRemoved(): void
    {
        self::entityManager()
            ->createQuery('UPDATE '.Repository::class.' repository SET repository.syncState = :state, repository.access = :access WHERE repository.id = :id')
            ->setParameter('state', SyncState::Unavailable)
            ->setParameter('access', RepositoryAccess::Removed)
            ->setParameter('id', Ulid::fromString($this->repositoryId->toString()), UlidType::NAME)
            ->execute();
    }
}
