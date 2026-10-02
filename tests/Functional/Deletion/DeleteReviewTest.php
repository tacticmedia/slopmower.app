<?php

declare(strict_types=1);

namespace App\Tests\Functional\Deletion;

use App\Domain\Identity\RepositoryId;
use App\Entity\User;
use App\Git\PinRefs;
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

/**
 * V13. The local source "billing" is cloned, and an agent token of the user links the review commit.
 */
final class DeleteReviewTest extends WebTestCase
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

    private const array REVIEW_TABLES = ['review', 'change_request', 'comment', 'commit_link', 'review_event', 'location_cache_entry', 'draft', 'viewed_file'];

    private string $commit;

    private KernelBrowser $client;

    private User $user;

    private User $secondUser;

    private string $repositoryId;

    private string $secret;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->commit = $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->secondUser = self::secondUser($this->user);
        $this->repositoryId = self::connectAndClone($this->client);
        [, $this->secret] = self::storeToken($this->user);
    }

    #[TestDox('Deleting a review returns 204 and removes its rows, the drafts of every user, and its review pin')]
    public function testDeleteRemovesRowsDraftsAndReviewPin(): void
    {
        $reviewId = $this->reviewWithRows()['reviewId'];
        self::assertNotContains(0, self::tenantRowCountsOf($this->user->getTenantId(), self::REVIEW_TABLES), 'Each table has a row of the review.');
        self::assertSame([PinRefs::COMMITS.$this->commit => $this->commit, PinRefs::REVIEWS.$reviewId => $this->commit], self::pins($this->user, $this->repositoryId));
        $this->client->disableReboot();

        $response = self::apiRequest($this->client, 'DELETE', "/api/v1/reviews/{$reviewId}");

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getContent());
        self::assertSame(array_fill_keys(self::REVIEW_TABLES, 0), self::tenantRowCountsOf($this->user->getTenantId(), self::REVIEW_TABLES));
        self::assertSame([], self::pins($this->user, $this->repositoryId), 'The review pin, and the commit pin of its only link.');
        self::assertContains(['type' => 'repository', 'repositoryId' => $this->repositoryId], self::repositoriesTopicUpdates($this->user->getTenantId()));
    }

    #[TestDox('A commit pin shared with another review stays until its last link is gone')]
    public function testSharedCommitPinStaysUntilLastLinkIsGone(): void
    {
        $first = $this->reviewWithRows()['reviewId'];
        $second = $this->reviewWithRows()['reviewId'];

        self::assertSame(204, self::apiRequest($this->client, 'DELETE', "/api/v1/reviews/{$first}")->getStatusCode());
        $pinsAfterFirst = self::pins($this->user, $this->repositoryId);
        self::assertSame(204, self::apiRequest($this->client, 'DELETE', "/api/v1/reviews/{$second}")->getStatusCode());

        self::assertSame([PinRefs::COMMITS.$this->commit => $this->commit, PinRefs::REVIEWS.$second => $this->commit], $pinsAfterFirst);
        self::assertSame([], self::pins($this->user, $this->repositoryId));
    }

    #[TestDox('The index of the commit stays')]
    public function testIndexOfCommitStays(): void
    {
        $reviewId = $this->reviewWithRows()['reviewId'];
        $indexTables = ['indexed_file', 'indexed_blob', 'symbol'];
        $index = self::tenantRowCountsOf($this->user->getTenantId(), $indexTables);

        self::apiRequest($this->client, 'DELETE', "/api/v1/reviews/{$reviewId}");

        self::assertNotContains(0, $index);
        self::assertSame($index, self::tenantRowCountsOf($this->user->getTenantId(), $indexTables));
    }

    #[TestDox('A closed review and a review of a removed repository can be deleted')]
    public function testClosedReviewAndReviewOfRemovedRepositoryCanBeDeleted(): void
    {
        $closed = (string) self::startReviewThroughApi($this->client, $this->repositoryId)['id'];
        $ofRemoved = (string) self::startReviewThroughApi($this->client, $this->repositoryId)['id'];
        self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$closed}/close"), 200, 'review.schema.json');

        $closedResponse = self::apiRequest($this->client, 'DELETE', "/api/v1/reviews/{$closed}");
        self::fetchRepository()->removeAccess(self::freshRepository(RepositoryId::fromString($this->repositoryId)));
        self::runQueuedMessages();
        $removedResponse = self::apiRequest($this->client, 'DELETE', "/api/v1/reviews/{$ofRemoved}");

        self::assertSame(204, $closedResponse->getStatusCode());
        self::assertSame(204, $removedResponse->getStatusCode());
        self::assertSame(0, self::tenantRowCountsOf($this->user->getTenantId(), ['review'])['review']);
    }

    #[TestDox('The IDs of the review, its requests, and its drafts answer 404 afterwards')]
    public function testIdsAnswerNotFoundAfterwards(): void
    {
        ['reviewId' => $reviewId, 'requestId' => $requestId, 'draftIds' => [$draftId]] = $this->reviewWithRows();

        self::apiRequest($this->client, 'DELETE', "/api/v1/reviews/{$reviewId}");

        self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$reviewId}"), 404, 'not_found');
        self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$reviewId}/requests"), 404, 'not_found');
        self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/requests/{$requestId}"), 404, 'not_found');
        self::assertApiError(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$draftId}", ['body' => 'Later.']), 404, 'not_found');
        self::assertApiError(self::apiRequest($this->client, 'DELETE', "/api/v1/reviews/{$reviewId}"), 404, 'not_found');
    }

    /**
     * @return array{reviewId: string, requestId: string, draftIds: list<string>}
     */
    private function reviewWithRows(): array
    {
        return self::populatedReview($this->client, $this->user, $this->secondUser, $this->repositoryId, $this->secret, $this->commit);
    }
}
