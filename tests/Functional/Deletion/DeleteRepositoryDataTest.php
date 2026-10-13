<?php

declare(strict_types=1);

namespace App\Tests\Functional\Deletion;

use App\Domain\Identity\RepositoryId;
use App\Entity\User;
use App\Git\TenantDataPaths;
use App\Message\DeleteCloneJob;
use App\Tests\Support\Api\AgentApiFixtures;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\DraftApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Deletion\DeletionFixtures;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Live\PublishedUpdates;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;

/**
 * R6. The local source "billing" is cloned and has a review with a row in every review table, a scoped token, and an
 * index.
 */
final class DeleteRepositoryDataTest extends WebTestCase
{
    use AgentApiFixtures;
    use ApiFixtures;
    use DeletionFixtures;
    use DraftApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use PublishedUpdates;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const array REPOSITORY_TABLES = [
        'repository', 'review', 'change_request', 'comment', 'commit_link', 'review_event', 'location_cache_entry', 'draft', 'viewed_file',
        'indexed_file', 'indexed_blob', 'symbol', 'pull_request',
    ];

    private KernelBrowser $client;

    private User $user;

    private string $repositoryId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $commit = $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->repositoryId = self::connectAndClone($this->client);
        [, $secret] = self::storeToken($this->user);
        self::storeToken($this->user, self::freshRepository(RepositoryId::fromString($this->repositoryId)), name: 'scoped');
        self::populatedReview($this->client, $this->user, self::secondUser($this->user), $this->repositoryId, $secret, $commit);
        self::storePullRequest($this->repositoryId);
    }

    #[TestDox('A confirm value that differs from the name gives 422')]
    public function testConfirmOtherThanNameGivesInvalidInput(): void
    {
        $before = self::tenantRowCounts($this->user->getTenantId());

        $wrong = self::assertApiError(self::apiRequest($this->client, 'DELETE', "/api/v1/repositories/{$this->repositoryId}?confirm=Billing"), 422, 'invalid_input');
        $missing = self::assertApiError(self::apiRequest($this->client, 'DELETE', "/api/v1/repositories/{$this->repositoryId}"), 422, 'invalid_input');

        self::assertSame('confirm', $wrong['details']['violations'][0]['field'] ?? null);
        self::assertSame('confirm', $missing['details']['violations'][0]['field'] ?? null);
        self::assertSame($before, self::tenantRowCounts($this->user->getTenantId()));
    }

    #[TestDox('The response is 202, the repository and all its rows are gone, and repository_deleted is published')]
    public function testDeleteRemovesRepositoryRowsAndPublishes(): void
    {
        self::assertNotContains(0, self::tenantRowCountsOf($this->user->getTenantId(), self::REPOSITORY_TABLES), 'Each table has a row of the repository.');
        $this->client->disableReboot();

        $response = $this->deleteRepository();

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', (string) $response->getContent());
        self::assertSame(array_fill_keys(self::REPOSITORY_TABLES, 0), self::tenantRowCountsOf($this->user->getTenantId(), self::REPOSITORY_TABLES));
        self::assertSame(['api_token' => 1, 'app_user' => 2], self::tenantRowCountsOf($this->user->getTenantId(), ['api_token', 'app_user']), 'The unscoped token and the users stay.');
        self::assertContains(['type' => 'repository_deleted', 'repositoryId' => $this->repositoryId], self::repositoriesTopicUpdates($this->user->getTenantId()));
        self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$this->repositoryId}"), 404, 'not_found');
    }

    #[TestDox('The same directory connects again before the job runs')]
    public function testSameDirectoryConnectsAgainBeforeTheJobRuns(): void
    {
        $this->deleteRepository();
        self::assertContainsEquals(new DeleteCloneJob($this->user->getTenantId(), RepositoryId::fromString($this->repositoryId)), self::queuedMessages());

        $connected = self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/repositories', ['source' => 'local', 'directory' => 'billing']), 201, 'repository.schema.json');
        self::runQueuedMessages();

        self::assertNotSame($this->repositoryId, $connected['id']);
        self::assertDirectoryExists($this->clonePathOf($this->repositoryId), 'The old clone was still there at the connect.');
        self::assertDirectoryExists($this->clonePathOf((string) $connected['id']));
    }

    #[TestDox('The job deletes the clone')]
    public function testJobDeletesTheClone(): void
    {
        $this->deleteRepository();
        $cloneBeforeJob = is_dir($this->clonePathOf($this->repositoryId));

        self::runQueuedMessages();

        self::assertTrue($cloneBeforeJob);
        self::assertDirectoryDoesNotExist($this->clonePathOf($this->repositoryId));
    }

    #[TestDox('The same job deletes the clone, the index, and the location cache after an access removal, and the reviews stay')]
    public function testJobAfterAccessRemovalDeletesTheIndex(): void
    {
        $indexTables = ['indexed_file', 'indexed_blob', 'symbol', 'location_cache_entry'];
        $before = self::tenantRowCountsOf($this->user->getTenantId(), $indexTables);

        self::fetchRepository()->removeAccess(self::freshRepository(RepositoryId::fromString($this->repositoryId)));
        self::runQueuedMessages();

        self::assertNotContains(0, $before);
        self::assertSame(array_fill_keys($indexTables, 0), self::tenantRowCountsOf($this->user->getTenantId(), $indexTables));
        self::assertSame(['repository' => 1, 'review' => 1, 'change_request' => 1], self::tenantRowCountsOf($this->user->getTenantId(), ['repository', 'review', 'change_request']));
        self::assertDirectoryDoesNotExist($this->clonePathOf($this->repositoryId));
    }

    #[TestDox('Other repositories of the tenant are untouched')]
    public function testOtherRepositoriesAreUntouched(): void
    {
        $this->newSource('alpha');
        $other = self::connectAndClone($this->client, 'alpha');
        $otherReview = (string) self::startReviewThroughApi($this->client, $other)['id'];
        self::runQueuedMessages();
        $otherRequest = (string) self::openRequest($this->client, $otherReview)['id'];
        self::runQueuedMessages();

        $this->deleteRepository();
        self::runQueuedMessages();

        self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$other}"), 200, 'repository.schema.json');
        self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$otherReview}"), 200, 'review.schema.json');
        self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/requests/{$otherRequest}"), 200, 'request.schema.json');
        self::assertNotContains(0, self::tenantRowCountsOf($this->user->getTenantId(), ['review_event', 'location_cache_entry', 'indexed_file']));
        self::assertDirectoryExists($this->clonePathOf($other));
    }

    private function deleteRepository(): Response
    {
        return self::apiRequest($this->client, 'DELETE', "/api/v1/repositories/{$this->repositoryId}?confirm=billing");
    }

    private function clonePathOf(string $repositoryId): string
    {
        return self::getContainer()->get(TenantDataPaths::class)->clonePath($this->user->getTenantId(), RepositoryId::fromString($repositoryId));
    }

    /**
     * @return list<object>
     */
    private static function queuedMessages(): array
    {
        return array_values(array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), [...self::asyncTransport()->getSent()]));
    }
}
