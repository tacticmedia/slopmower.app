<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitHub;

use App\Domain\Identity\TenantId;
use App\Domain\Repository\RepositoryAccess;
use App\Domain\Repository\RepositoryName;
use App\Domain\Repository\SyncState;
use App\Entity\Repository;
use App\Entity\User;
use App\Git\TenantDataPaths;
use App\GitHub\InstallationSync;
use App\Message\DeleteCloneJob;
use App\Repository\RepositoryRepository;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\GitHub\GitHubResponses;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Messenger\QueuedMessages;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Uid\Ulid;

/**
 * The first test signs in through the OAuth callback. The others call the sync with the user tokens that select the
 * recorded listings.
 */
final class InstallationSyncTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use EnvironmentVariables;
    use GitHubRows;
    use JsonApiAssertions;
    use LocalGitHub;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    private const string CANDIDATES_QUERY = 'SELECT github_repository_id, installation_id, account_login, owner, name, private, default_branch, html_url, listed_at FROM github_candidate ORDER BY github_repository_id';

    #[TestDox('Sign-in records the installations and the candidates')]
    public function testSignInRecordsInstallationsAndCandidates(): void
    {
        self::mockTime('2026-10-02 07:00:00');
        $client = static::createClient();
        $client->request('GET', '/connect/github');
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);

        $client->request('GET', '/login/check-github', ['code' => 'a1b2c3d4', 'state' => $query['state'] ?? '']);

        self::assertResponseRedirects('http://localhost/');
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(
            [
                ['installation_id' => 5551, 'account_login' => 'acme', 'account_type' => 'Organization', 'suspended_at' => null],
                ['installation_id' => 5552, 'account_login' => 'octocat', 'account_type' => 'User', 'suspended_at' => null],
            ],
            $connection->fetchAllAssociative('SELECT installation_id, account_login, account_type, suspended_at FROM github_installation ORDER BY installation_id'),
            'The enterprise installation holds no repositories.',
        );
        self::assertSame(
            [
                [812345, 5551, 'acme', 'acme', 'billing', true, 'main', 'https://github.com/acme/billing', '2026-10-02 07:00:00'],
                [812399, 5551, 'acme', 'acme', 'Shipping', true, 'develop', 'https://github.com/acme/Shipping', '2026-10-02 07:00:00'],
                [1296269, 5552, 'octocat', 'octocat', 'Hello-World', false, 'master', 'https://github.com/octocat/Hello-World', '2026-10-02 07:00:00'],
            ],
            $connection->fetchAllNumeric(self::CANDIDATES_QUERY),
        );
    }

    #[TestDox('A connected repository that is no longer listed becomes removed, and its clone and index are deleted')]
    public function testUnlistedRepositoryIsRemoved(): void
    {
        $this->useLocalGitHub();
        $this->gitHubSource('acme/billing');
        $client = static::createClient();
        $user = self::syncedUser();
        $repository = self::connectGitHub($user->getTenantId());
        self::runQueuedMessages();
        $clonePath = self::getContainer()->get(TenantDataPaths::class)->clonePath($user->getTenantId(), $repository->getId());
        self::assertDirectoryExists($clonePath);

        self::sync($user, GitHubResponses::BILLING_REMOVED_ACCESS_TOKEN);

        $removed = self::getContainer()->get(RepositoryRepository::class)->findFresh($repository->getId());
        self::assertSame([RepositoryAccess::Removed, SyncState::Unavailable], [$removed?->getAccess(), $removed?->getSyncState()]);
        self::assertSame([DeleteCloneJob::class], array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, [...self::asyncTransport()->get()]));
        self::runQueuedMessages();
        self::assertDirectoryDoesNotExist($clonePath);
        // No index exists before M26.
        self::assertSame([812399, 1296269], self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT github_repository_id FROM github_candidate ORDER BY github_repository_id'));
        $client->loginUser($user, 'web');
        $response = self::assertApiResponse(self::apiRequest($client, 'GET', '/api/v1/repositories/'.$repository->getId()), 200, 'repository.schema.json');
        self::assertSame(['access' => 'removed', 'state' => 'unavailable'], ['access' => $response['access'], 'state' => ((array) $response['sync'])['state']]);
    }

    #[TestDox('The sync leaves the installation, the candidate, and the connected repository of another tenant unchanged')]
    public function testSyncLeavesAnotherTenantUnchanged(): void
    {
        static::createClient();
        $other = TenantFixtures::of(self::getContainer())->withLogin('mona')->withGitHubUser(2)->create()->getTenantId();
        self::storeInstallation($other);
        self::storeCandidate($other);
        self::connectGitHub($other);
        $otherRows = self::tenantRows($other);
        $user = self::syncedUser();
        self::connectGitHub($user->getTenantId());

        self::sync($user, GitHubResponses::BILLING_REMOVED_ACCESS_TOKEN);

        self::assertSame($otherRows, self::tenantRows($other));
        self::assertSame('active', $otherRows['repository'][0]['access'] ?? null);
        self::assertSame('removed', self::tenantRows($user->getTenantId())['repository'][0]['access'] ?? null, 'The same GitHub repository loses its access in the signed-in tenant.');
    }

    #[TestDox('Sign-in updates the name and the visibility of a connected repository')]
    public function testSignInUpdatesConnectedRepository(): void
    {
        static::createClient();
        $user = TenantFixtures::of(self::getContainer())->withLogin('octocat')->withGitHubUser(1)->create();
        $repository = Repository::gitHub($user->getTenantId(), RepositoryName::github('acme/old-billing'), 812345, 5551, false, Clock::get()->now());
        self::storeRow($repository);

        self::sync($user, GitHubResponses::ACCESS_TOKEN);

        $renamed = self::getContainer()->get(RepositoryRepository::class)->findFresh($repository->getId());
        self::assertSame(['acme/billing', true], [$renamed?->getName()->toString(), $renamed?->isGithubPrivate()]);
    }

    #[TestDox('A failed or partial listing removes nothing')]
    #[DataProvider('failedListings')]
    public function testFailedListingRemovesNothing(string $accessToken): void
    {
        static::createClient();
        $user = self::syncedUser();
        $repository = self::connectGitHub($user->getTenantId());
        $connection = self::getContainer()->get(Connection::class);
        $candidates = $connection->fetchAllNumeric(self::CANDIDATES_QUERY);

        self::sync($user, $accessToken);

        self::assertSame(RepositoryAccess::Active, self::getContainer()->get(RepositoryRepository::class)->findFresh($repository->getId())?->getAccess());
        self::assertSame($candidates, $connection->fetchAllNumeric(self::CANDIDATES_QUERY));
        self::assertSame(2, (int) $connection->fetchOne('SELECT count(*) FROM github_installation'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failedListings(): iterable
    {
        yield 'failed' => [GitHubResponses::FAILED_LISTING_ACCESS_TOKEN];
        yield 'partial' => [GitHubResponses::PARTIAL_LISTING_ACCESS_TOKEN];
    }

    #[TestDox('R2 lists the GitHub candidates with githubListedAt')]
    public function testCandidatesListTheGitHubCandidates(): void
    {
        self::mockTime('2026-10-02 07:00:00');
        $client = static::createClient();
        $user = self::syncedUser();
        $connectedId = self::connectGitHub($user->getTenantId(), 812399)->getId()->toString();
        self::storeCandidate(TenantFixtures::of(self::getContainer())->withLogin('mona')->withGitHubUser(2)->create()->getTenantId(), 900001, 'mona/private-notes');
        $client->loginUser($user, 'web');

        $list = self::assertApiResponse(self::apiRequest($client, 'GET', '/api/v1/repositories/candidates'), 200, 'lists.schema.json#/$defs/CandidateList');

        self::assertSame(
            [
                ['source' => 'github', 'name' => 'acme/billing', 'connectedRepositoryId' => null, 'github' => ['repositoryId' => 812345, 'installationId' => 5551, 'accountLogin' => 'acme', 'owner' => 'acme', 'private' => true, 'defaultBranch' => 'main', 'htmlUrl' => 'https://github.com/acme/billing'], 'local' => null],
                ['source' => 'github', 'name' => 'acme/Shipping', 'connectedRepositoryId' => $connectedId, 'github' => ['repositoryId' => 812399, 'installationId' => 5551, 'accountLogin' => 'acme', 'owner' => 'acme', 'private' => true, 'defaultBranch' => 'develop', 'htmlUrl' => 'https://github.com/acme/Shipping'], 'local' => null],
                ['source' => 'github', 'name' => 'octocat/Hello-World', 'connectedRepositoryId' => null, 'github' => ['repositoryId' => 1296269, 'installationId' => 5552, 'accountLogin' => 'octocat', 'owner' => 'octocat', 'private' => false, 'defaultBranch' => 'master', 'htmlUrl' => 'https://github.com/octocat/Hello-World'], 'local' => null],
            ],
            array_values(array_filter((array) $list['items'], static fn (mixed $item): bool => \is_array($item) && 'github' === $item['source'])),
            "Sorted by name case-insensitively, without the other tenant's candidate.",
        );
        self::assertSame(['githubListedAt' => '2026-10-02T07:00:00Z', 'githubConfigureUrl' => 'https://github.com/apps/holistic-review-test/installations/new'], array_intersect_key($list, ['githubListedAt' => 0, 'githubConfigureUrl' => 0]));
    }

    /**
     * The GitHub user of the recorded responses, after a sync with the full listing.
     */
    private static function syncedUser(): User
    {
        $user = TenantFixtures::of(self::getContainer())->withLogin('octocat')->withGitHubUser(1)->create();
        self::sync($user, GitHubResponses::ACCESS_TOKEN);

        return $user;
    }

    private static function sync(User $user, string $userToken): void
    {
        self::getContainer()->get(InstallationSync::class)->sync($user, $userToken);
    }

    /**
     * @return array<string, list<array<string, mixed>>> The GitHub rows of the tenant by table
     */
    private static function tenantRows(TenantId $tenantId): array
    {
        $connection = self::getContainer()->get(Connection::class);
        $tenant = Ulid::fromString($tenantId->toString())->toRfc4122();

        return [
            'github_installation' => $connection->fetchAllAssociative('SELECT * FROM github_installation WHERE tenant_id = ? ORDER BY installation_id', [$tenant]),
            'github_candidate' => $connection->fetchAllAssociative('SELECT * FROM github_candidate WHERE tenant_id = ? ORDER BY github_repository_id', [$tenant]),
            'repository' => $connection->fetchAllAssociative('SELECT * FROM repository WHERE tenant_id = ? ORDER BY id', [$tenant]),
        ];
    }
}
