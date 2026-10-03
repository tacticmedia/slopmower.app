<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhook;

use App\Domain\Repository\RepositoryAccess;
use App\Domain\Repository\SyncState;
use App\Entity\Repository;
use App\Git\TenantDataPaths;
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
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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

    #[TestDox('installation deleted marks the repositories removed, deletes the clones, and keeps the reviews')]
    public function testInstallationDeletedRemovesAccess(): void
    {
        $this->useLocalGitHub();
        $this->gitHubSource();
        $client = static::createClient();
        $repository = self::clonedGitHubRepository(1);
        self::getContainer()->get(TenantContext::class)->set($repository->getTenantId());
        self::startReview($repository->getId());
        self::assertDirectoryExists(self::clonePath($repository));

        self::deliverFixture($client, 'installation.deleted');
        self::runQueuedMessages();

        $stored = self::storedRepository($repository);
        self::assertSame([RepositoryAccess::Removed, SyncState::Unavailable], [$stored->getAccess(), $stored->getSyncState()]);
        self::assertDirectoryDoesNotExist(self::clonePath($repository));
        self::assertSame(1, self::countTenantRows('review', $repository->getTenantId()));
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

    private static function clonePath(Repository $repository): string
    {
        return self::getContainer()->get(TenantDataPaths::class)->clonePath($repository->getTenantId(), $repository->getId());
    }
}
