<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitHub;

use App\Application\FetchRepository;
use App\Application\ReviewCommands;
use App\Application\ServerClone;
use App\Domain\Actor\Actor;
use App\Domain\Git\RefName;
use App\Domain\Repository\CodeAccess;
use App\Domain\Repository\SyncState;
use App\Entity\Repository;
use App\Entity\User;
use App\Git\TenantDataPaths;
use App\Message\CloneRepositoryJob;
use App\Message\FetchRepositoryJob;
use App\Repository\UserRepository;
use App\Tenancy\TenantContext;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\GitHub\ClonedGitHubRepositories;
use App\Tests\Support\GitHub\GitHubResponses;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Messenger\QueuedMessages;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * R10 (D111): a change of the code access through the clone job.
 */
final class CodeAccessSwitchTest extends WebTestCase
{
    use ApiFixtures;
    use ClonedGitHubRepositories;
    use EnvironmentVariables;
    use GitHubRows;
    use JsonApiAssertions;
    use LocalGitHub;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    private KernelBrowser $client;

    private Repository $repository;

    private string $clonePath;

    private GitRepositoryBuilder $source;

    protected function setUp(): void
    {
        $this->useLocalGitHub();
        $this->setEnvironmentVariable('APP_SCRATCH_DIR', $this->temporaryDirectory());
        $this->source = $this->gitHubSource();
        $this->source->commit(['src/Tax.php' => "<?php\n\nfinal class Tax\n{\n}\n"]);
        $this->source->git('push', '--quiet', 'origin', 'main');
        $this->client = static::createClient();
        $this->repository = self::clonedGitHubRepository(1, login: 'octocat');
        self::getContainer()->get(TenantContext::class)->set($this->repository->getTenantId());
        $this->client->loginUser(self::user($this->repository), 'web');
        $this->clonePath = self::getContainer()->get(TenantDataPaths::class)->clonePath($this->repository->getTenantId(), $this->repository->getId());
    }

    #[TestDox('Leaving Server copy deletes the clone and keeps the index, and entering it clones again')]
    public function testSwitchDeletesAndRestoresTheClone(): void
    {
        self::getContainer()->get(ReviewCommands::class)->start($this->repository->getId(), null, null, Actor::user(self::user($this->repository)->getId()));
        self::runQueuedMessages();
        $indexedFiles = $this->indexedFileCount();

        $leaving = $this->switchTo('pass_through');
        self::runQueuedMessages();
        $left = self::storedRepository($this->repository);

        self::assertSame(['pass_through', 'cloning'], [$leaving['codeAccess'], $leaving['sync']['state'] ?? null]);
        self::assertDirectoryDoesNotExist($this->clonePath);
        self::assertSame([SyncState::Idle, null, 2], [$left->getSyncState(), $left->getCloneSizeBytes(), $indexedFiles]);
        self::assertSame($indexedFiles, $this->indexedFileCount());

        $this->switchTo('clone');
        self::runQueuedMessages();

        self::assertDirectoryExists($this->clonePath);
        self::assertSame(SyncState::Idle, self::storedRepository($this->repository)->getSyncState());
    }

    #[TestDox('A fetch job queued before a switch to Pass-through and run after its clone job creates no clone')]
    public function testFetchQueuedBeforeTheSwitchCreatesNoClone(): void
    {
        $fetchRepository = self::getContainer()->get(FetchRepository::class);
        $fetchRepository->request($this->repository->getId());
        $fetchRepository->changeCodeAccess($this->repository, CodeAccess::PassThrough);
        $queued = [...self::asyncTransport()->get(10)];
        self::assertSame([FetchRepositoryJob::class, CloneRepositoryJob::class], array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, $queued));

        foreach ([$queued[1], $queued[0]] as $envelope) {
            self::asyncTransport()->ack(self::getContainer()->get(MessageBusInterface::class)->dispatch($envelope->with(new ReceivedStamp('async'))));
        }

        self::assertDirectoryDoesNotExist($this->clonePath);
        self::assertSame(SyncState::Idle, self::storedRepository($this->repository)->getSyncState());
    }

    #[TestDox('A review commit on no branch stays readable after a switch to Pass-through and back')]
    public function testReviewCommitOnNoBranchSurvivesTheRoundTrip(): void
    {
        $this->source->git('checkout', '--quiet', '-b', 'feature');
        $this->source->commit(['src/Feature.php' => "<?php\n\nfinal class Feature\n{\n}\n"]);
        $this->source->git('push', '--quiet', 'origin', 'feature');
        $reviewId = self::getContainer()->get(ReviewCommands::class)->start($this->repository->getId(), RefName::fromString('feature'), null, Actor::user(self::user($this->repository)->getId()));
        self::runQueuedMessages();
        $this->source->git('push', '--quiet', 'origin', '--delete', 'feature');

        $this->switchTo('pass_through');
        self::runQueuedMessages();
        $this->switchTo('clone');
        self::runQueuedMessages();

        self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$reviewId}/file?path=src%2FFeature.php"), 200, 'file-content.schema.json');
    }

    #[TestDox('A head sync that fails while a switch to Server copy waits leaves the clone job able to run')]
    public function testFailedHeadSyncDuringASwitchLeavesTheCloneJob(): void
    {
        $this->switchTo('pass_through');
        self::runQueuedMessages();
        $passThrough = self::storedRepository($this->repository);
        $this->switchTo('clone');
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('UPDATE app_user SET login = ? WHERE tenant_id = ?', [GitHubResponses::FORMER_MEMBER_LOGIN, $this->repository->getTenantId()->toRfc4122()]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::getContainer()->get(ServerClone::class)->fetch($passThrough, false, 60.0);
        $during = self::storedRepository($this->repository)->getSyncState();
        $connection->executeStatement('UPDATE app_user SET login = ? WHERE tenant_id = ?', ['octocat', $this->repository->getTenantId()->toRfc4122()]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::runQueuedMessages();

        self::assertSame([SyncState::Cloning, SyncState::Idle], [$during, self::storedRepository($this->repository)->getSyncState()]);
        self::assertDirectoryExists($this->clonePath);
    }

    /**
     * @return array<string, mixed> The repository that R10 returned
     */
    private function switchTo(string $codeAccess): array
    {
        return self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/repositories/{$this->repository->getId()}/code-access", ['codeAccess' => $codeAccess]), 200, 'repository.schema.json');
    }

    private function indexedFileCount(): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM indexed_file WHERE repository_id = ?', [$this->repository->getId()->toRfc4122()]);
    }

    private static function user(Repository $repository): User
    {
        return self::getContainer()->get(UserRepository::class)->findOneBy(['tenantId' => $repository->getTenantId()]) ?? self::fail('The tenant has a user.');
    }
}
