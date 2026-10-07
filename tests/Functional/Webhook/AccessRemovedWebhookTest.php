<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhook;

use App\Anchoring\LocationCacheSql;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\EventId;
use App\Domain\Identity\ReviewId;
use App\Domain\Location\Location;
use App\Domain\Repository\RepositoryAccess;
use App\Domain\Repository\SyncState;
use App\Entity\Repository;
use App\Git\ObjectType;
use App\Git\TenantDataPaths;
use App\Git\TreeEntry;
use App\Index\IndexSql;
use App\Repository\RepositoryRepository;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\EventStore\CommandFixtures;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\GitHub\ClonedGitHubRepositories;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\GitHubWebhooks;
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Live\PublishedUpdates;
use App\Tests\Support\Messenger\QueuedMessages;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;

/**
 * Decision D22.
 */
final class AccessRemovedWebhookTest extends WebTestCase
{
    use ClonedGitHubRepositories;
    use CommandFixtures;
    use EnvironmentVariables;
    use GitHubRows;
    use GitHubWebhooks;
    use LocalGitHub;
    use PublishedUpdates;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    #[TestDox('installation deleted marks the repositories removed, deletes the clones, the index, and the location cache, and keeps the reviews')]
    public function testInstallationDeletedRemovesAccess(): void
    {
        $this->useLocalGitHub();
        $this->gitHubSource();
        $client = static::createClient();
        $repository = self::clonedGitHubRepository(1);
        $tenantId = $repository->getTenantId();
        self::getContainer()->get(TenantContext::class)->set($tenantId);
        self::storeIndexAndLocation($repository, self::startReview($repository->getId()));
        self::assertDirectoryExists(self::clonePath($repository));
        self::assertSame([1, 1], [self::countTenantRows('indexed_file', $tenantId), self::countTenantRows('location_cache_entry', $tenantId)]);

        self::deliverFixture($client, 'installation.deleted');
        self::runQueuedMessages();

        $stored = self::storedRepository($repository);
        self::assertSame([RepositoryAccess::Removed, SyncState::Unavailable], [$stored->getAccess(), $stored->getSyncState()]);
        self::assertDirectoryDoesNotExist(self::clonePath($repository));
        self::assertSame([0, 0], [self::countTenantRows('indexed_file', $tenantId), self::countTenantRows('location_cache_entry', $tenantId)]);
        self::assertSame(1, self::countTenantRows('review', $tenantId));
        self::assertContains(['type' => 'repository', 'repositoryId' => $repository->getId()->toString()], self::repositoriesTopicUpdates($repository->getTenantId()));
    }

    #[TestDox('installation deleted removes the candidates of the installation and publishes candidates_changed')]
    public function testInstallationDeletedRemovesCandidates(): void
    {
        $client = static::createClient();
        $tenantId = TenantFixtures::of(self::getContainer())->withGitHubUser(1)->create()->getTenantId();
        self::storeInstallation($tenantId);
        self::storeInstallation($tenantId, 5552, 'octocat');
        self::storeCandidate($tenantId);
        self::storeCandidate($tenantId, 812399, 'acme/shipping');
        self::storeCandidate($tenantId, 1296269, 'octocat/Hello-World', 5552);
        // installation_repositories.added leaves candidates without an installation row.
        $candidatesOnly = TenantFixtures::of(self::getContainer())->withGitHubUser(2)->create()->getTenantId();
        self::storeCandidate($candidatesOnly);

        self::deliverFixture($client, 'installation.deleted');
        self::runQueuedMessages();

        self::assertSame([1296269], self::storedCandidateIds($tenantId));
        self::assertSame(1, self::countTenantRows('github_installation', $tenantId));
        self::assertContains(['type' => 'candidates_changed'], self::repositoriesTopicUpdates($tenantId));
        self::assertSame([], self::storedCandidateIds($candidatesOnly));
        self::assertContains(['type' => 'candidates_changed'], self::repositoriesTopicUpdates($candidatesOnly));
    }

    #[TestDox('installation_repositories removed does the same for the listed repositories')]
    public function testInstallationRepositoriesRemoved(): void
    {
        $this->useLocalGitHub();
        $this->gitHubSource();
        $client = static::createClient();
        $repository = self::clonedGitHubRepository(1);
        self::storeCandidate($repository->getTenantId(), 812399, 'acme/shipping');

        self::deliverFixture($client, 'installation_repositories.removed');
        self::runQueuedMessages();

        $stored = self::storedRepository($repository);
        self::assertSame([RepositoryAccess::Removed, SyncState::Unavailable], [$stored->getAccess(), $stored->getSyncState()]);
        self::assertDirectoryDoesNotExist(self::clonePath($repository));
        self::assertSame([812399], self::storedCandidateIds($repository->getTenantId()));
        self::assertSame(1, self::countTenantRows('github_installation', $repository->getTenantId()));
        $updates = self::repositoriesTopicUpdates($repository->getTenantId());
        self::assertContains(['type' => 'repository', 'repositoryId' => $repository->getId()->toString()], $updates);
        self::assertContains(['type' => 'candidates_changed'], $updates);
    }

    #[TestDox('A repeated removal deletes the clone that an earlier removal left')]
    public function testRepeatedRemovalDeletesALeftClone(): void
    {
        $this->useLocalGitHub();
        $this->gitHubSource();
        $client = static::createClient();
        $repository = self::clonedGitHubRepository(1);
        // An earlier removal marked the repository, and then its dispatch failed.
        self::getContainer()->get(TenantContext::class)->set($repository->getTenantId());
        self::getContainer()->get(RepositoryRepository::class)->removeAccess($repository->getId());

        self::deliverFixture($client, 'installation_repositories.removed');
        self::runQueuedMessages();

        self::assertDirectoryDoesNotExist(self::clonePath($repository));
    }

    /**
     * An indexed file, and a location cache entry of the review.started event, which stands for a request.opened event.
     */
    private static function storeIndexAndLocation(Repository $repository, ReviewId $reviewId): void
    {
        $tenantId = $repository->getTenantId();
        $commit = CommitSha::fromString(self::REVIEW_COMMIT);
        $readme = RepoPath::fromString('README.md');
        self::getContainer()->get(IndexSql::class)->insertFiles($tenantId, $repository->getId(), $commit, [
            new TreeEntry($readme->toString(), '100644', ObjectType::Blob, 'e965047ad7c57865823c7d992b1d046ea66edf78', 6),
        ]);
        $startedEventId = (string) self::getContainer()->get(Connection::class)
            ->fetchOne('SELECT id FROM review_event WHERE tenant_id = ? AND review_id = ?', [$tenantId->toRfc4122(), $reviewId->toRfc4122()]);
        self::getContainer()->get(LocationCacheSql::class)
            ->insert($tenantId, $repository->getId(), EventId::fromUlid(Ulid::fromString($startedEventId)), Location::exact($commit, $readme, null));
    }

    private static function clonePath(Repository $repository): string
    {
        return self::getContainer()->get(TenantDataPaths::class)->clonePath($repository->getTenantId(), $repository->getId());
    }
}
