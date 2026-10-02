<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The user connected the local source "billing" and works through the API; the agent reads through MCP.
 */
final class ReadToolsTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use McpAssertions;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private KernelBrowser $client;

    private McpTestClient $mcp;

    private string $repositoryId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource();
        $this->client = static::createClient();
        $user = self::signIn($this->client);
        $this->repositoryId = self::connectAndClone($this->client);
        [, $secret] = self::storeToken($user);
        $this->mcp = new McpTestClient($this->client, $secret);
    }

    #[TestDox('list_reviews returns only open reviews by default')]
    public function testListReviewsReturnsOnlyOpenReviewsByDefault(): void
    {
        $closed = $this->startReview();
        $open = $this->startReview();
        $this->closeReview($closed);

        $page = $this->listReviews(['repository' => 'billing']);

        self::assertSame([[$open, 'open']], self::idsAndStates($page));
    }

    #[TestDox('list_reviews returns both reviews when two are open, and closed ones with includeClosed')]
    public function testListReviewsReturnsBothOpenReviewsAndClosedOnesWithIncludeClosed(): void
    {
        $older = $this->startReview();
        $newer = $this->startReview();

        $bothOpen = $this->listReviews(['repository' => 'billing']);
        $this->closeReview($older);
        $withClosed = $this->listReviews(['repository' => 'billing', 'includeClosed' => true]);

        self::assertSame([[$newer, 'open'], [$older, 'open']], self::idsAndStates($bothOpen));
        self::assertSame([[$newer, 'open'], [$older, 'closed']], self::idsAndStates($withClosed));
    }

    #[TestDox('list_reviews resolves the repository by ID and by local directory name')]
    public function testListReviewsResolvesTheRepositoryByIdAndByName(): void
    {
        $review = $this->startReview();

        $byId = $this->listReviews(['repository' => $this->repositoryId]);
        $byName = $this->listReviews(['repository' => 'billing']);

        self::assertSame($byId, $byName);
        self::assertIsArray($byId['repository']);
        self::assertSame($this->repositoryId, $byId['repository']['id']);
        self::assertSame([[$review, 'open']], self::idsAndStates($byId));
    }

    #[TestDox('get_updates returns a cursor that the next call accepts')]
    public function testGetUpdatesReturnsACursorThatTheNextCallAccepts(): void
    {
        $review = $this->startReview();
        $request = (string) self::openRequest($this->client, $review)['id'];

        $waiting = $this->getUpdates(['review' => $review]);
        self::requestCommand($this->client, $request, 'comments', ['body' => 'Keep the old method as deprecated.']);
        $next = $this->getUpdates(['review' => $review, 'cursor' => $waiting['cursor']]);

        self::assertIsArray($waiting['requests']);
        self::assertSame([$request], array_column(array_column($waiting['requests'], 'request'), 'id'));
        self::assertIsArray($next['requests']);
        self::assertSame([[$request, ['request.commented']]], array_map(
            static fn (array $updated): array => [$updated['request']['id'], array_column($updated['newEvents'], 'type')],
            $next['requests'],
        ));
        self::assertGreaterThan($waiting['cursor'], $next['cursor']);
    }

    #[TestDox('content text equals the structuredContent JSON')]
    public function testContentTextEqualsStructuredContent(): void
    {
        $review = $this->startReview();
        $request = (string) self::openRequest($this->client, $review)['id'];

        $result = $this->mcp->callTool('get_request', ['id' => $request]);

        self::assertSame($request, self::assertToolResult($this->mcp, $result, 'get_request')['id']);
        self::assertIsArray($result['content']);
        self::assertCount(1, $result['content']);
        self::assertSame('text', $result['content'][0]['type']);
        self::assertSame($result['structuredContent'], json_decode($result['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR));
    }

    private function startReview(): string
    {
        return (string) self::startReviewThroughApi($this->client, $this->repositoryId)['id'];
    }

    private function closeReview(string $reviewId): void
    {
        self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$reviewId}/close"), 200, 'review.schema.json');
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> The ReviewPage
     */
    private function listReviews(array $arguments): array
    {
        return self::assertToolResult($this->mcp, $this->mcp->callTool('list_reviews', $arguments), 'list_reviews');
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> The UpdatesPage
     */
    private function getUpdates(array $arguments): array
    {
        return self::assertToolResult($this->mcp, $this->mcp->callTool('get_updates', $arguments), 'get_updates');
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return list<array{mixed, mixed}>
     */
    private static function idsAndStates(array $page): array
    {
        self::assertIsArray($page['items']);

        return array_values(array_map(static fn (array $review): array => [$review['id'], $review['state']], $page['items']));
    }
}
