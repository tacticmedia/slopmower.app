<?php

declare(strict_types=1);

namespace App\Tests\Functional\Source;

use App\Domain\Git\CommitSha;
use App\Domain\Repository\SyncState;
use App\Repository\RepositoryRepository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;

final class ConnectLocalRepositoryTest extends KernelTestCase
{
    use EnvironmentVariables;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewDataFixtures;
    use TemporaryDirectories;

    private const int OTHER_USER_ID = 1234;

    private const array HEADS_AND_TAGS = ['for-each-ref', '--format=%(refname) %(objectname)', 'refs/heads', 'refs/tags'];

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
    }

    #[TestDox('The clone job writes a bare clone at the ID path')]
    public function testClonesAtTheIdPath(): void
    {
        $this->newSource();
        $tenantId = self::enterNewTenant();
        $repositoryId = self::connect()->getId();

        self::runQueuedMessages();

        $repository = self::freshRepository($repositoryId);
        self::assertSame(SyncState::Idle, $repository->getSyncState(), (string) $repository->getLastError());
        self::assertSame("{$this->tenantDataDirectory}/{$tenantId}/repositories/{$repositoryId}.git", self::clonePath($repository));
        self::assertSame("true\n", self::cloneGit($repository, 'rev-parse', '--is-bare-repository'));
    }

    #[TestDox('The clone heads equal the source heads')]
    public function testCloneHeadsEqualSourceHeads(): void
    {
        $source = $this->newSource();
        $source->git('tag', 'v1');
        $source->switch('develop')->commit(['develop.txt' => "Develop\n"]);
        $source->switch('main');
        self::enterNewTenant();

        $repository = self::connect();
        self::runQueuedMessages();

        self::assertSame($source->git(...self::HEADS_AND_TAGS), self::cloneGit($repository, ...self::HEADS_AND_TAGS));
    }

    #[TestDox('Detects the default branch')]
    public function testDetectsTheDefaultBranch(): void
    {
        $developHead = $this->newSource()->switch('develop')->commit(['develop.txt' => "Develop\n"]);
        self::enterNewTenant();

        $repositoryId = self::connect()->getId();
        self::runQueuedMessages();

        $repository = self::freshRepository($repositoryId);
        self::assertSame('develop', $repository->getDefaultBranch());
        self::assertEquals(CommitSha::fromString($developHead), $repository->getDefaultBranchHead());
    }

    #[TestDox('A source owned by another user clones and fetches through safe.directory')]
    public function testSourceOwnedByAnotherUser(): void
    {
        // The bind mount of the project directory does not keep a changed owner.
        $localRepositories = sys_get_temp_dir().'/slopmower-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir($localRepositories);

        try {
            $this->useTemporaryLocalSources($localRepositories);
            $source = $this->newSource();
            $filesystem->chown($source->path, self::OTHER_USER_ID, true);
            clearstatcache();
            self::assertSame(self::OTHER_USER_ID, fileowner("{$source->path}/.git"));
            self::enterNewTenant();

            $repositoryId = self::connect()->getId();
            self::runQueuedMessages();

            $repository = self::freshRepository($repositoryId);
            self::assertSame(SyncState::Idle, $repository->getSyncState(), (string) $repository->getLastError());

            $filesystem->chown($source->path, 0, true);
            $newHead = $source->commit(['later.txt' => "Later\n"]);
            $filesystem->chown($source->path, self::OTHER_USER_ID, true);

            self::fetchRepository()->fetchNow($repositoryId);

            self::assertSame("{$newHead}\n", self::cloneGit($repository, 'rev-parse', 'refs/heads/main'));
        } finally {
            $filesystem->remove($localRepositories);
        }
    }

    #[TestDox('A failed dispatch of the clone job leaves the repository failed, so R5 clones again')]
    public function testFailedDispatchLeavesTheRepositoryFailed(): void
    {
        $this->newSource();
        self::enterNewTenant();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $failSending = static fn (SendMessageToTransportsEvent $event): never => throw new \RuntimeException('The transport is down.');
        $dispatcher->addListener(SendMessageToTransportsEvent::class, $failSending);

        try {
            self::connect();
            self::fail('The connect did not fail.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The transport is down.', $failure->getMessage());
        } finally {
            $dispatcher->removeListener(SendMessageToTransportsEvent::class, $failSending);
        }

        $repositoryId = (self::getContainer()->get(RepositoryRepository::class)->findLocal('billing') ?? self::fail('No row.'))->getId();
        self::assertSame(SyncState::Failed, self::freshRepository($repositoryId)->getSyncState());
        self::assertSame('The clone job could not be queued.', self::freshRepository($repositoryId)->getLastError());

        self::fetchRepository()->request($repositoryId);

        self::assertSame(SyncState::Cloning, self::freshRepository($repositoryId)->getSyncState());
        self::runQueuedMessages();
        self::assertSame(SyncState::Idle, self::freshRepository($repositoryId)->getSyncState());
    }
}
