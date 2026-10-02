<?php

declare(strict_types=1);

namespace App\Tests\Functional\Slice;

use App\Domain\Actor\Actor;
use App\Domain\Event\RecordedEvent;
use App\Tests\Support\Api\AgentApiFixtures;
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
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The headless slice: the user works through session REST, the agent through MCP.
 */
final class HeadlessSliceTest extends WebTestCase
{
    use AgentApiFixtures;
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

    #[TestDox('The user and the agent complete a request through REST and MCP')]
    public function testUserAndAgentCompleteRequest(): void
    {
        $this->useTemporaryLocalSources();
        $source = $this->newSource();
        $source->commit(self::codeFiles(), 'Add invoice totals');
        $client = static::createClient();
        $user = self::signIn($client);

        $repositoryId = self::connectAndClone($client);
        $reviewId = (string) self::startReviewThroughApi($client, $repositoryId)['id'];
        $requestId = (string) self::openRequest($client, $reviewId, ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 3, 'endLine' => 5]], 'Extract the tax rounding into `TaxRounding`.')['id'];

        $mcp = new McpTestClient($client, self::storeToken($user)[1]);
        $reviews = self::assertToolResult($mcp, $mcp->callTool('list_reviews', ['repository' => 'billing']), 'list_reviews');
        $requests = self::assertToolResult($mcp, $mcp->callTool('list_requests', ['review' => $reviewId, 'awaiting' => 'agent']), 'list_requests');
        $request = self::assertToolResult($mcp, $mcp->callTool('get_request', ['id' => $requestId]), 'get_request');
        $claimed = self::assertToolResult($mcp, $mcp->callTool('claim_request', ['id' => $requestId]), 'claim_request');
        self::assertSame([$reviewId], array_column($reviews['items'], 'id'));
        self::assertSame([$requestId], array_column($requests['items'], 'id'));
        self::assertSame([self::INVOICE_TOTALS, ['reply', 'claim', 'mark_wont_do']], [$request['anchor']['path'], $request['allowedActions']]);
        self::assertSame('in_progress', $claimed['request']['status']);

        $fix = $source->switch('fix')->commit(['src/Billing/TaxRounding.php' => "<?php\n\nfinal class TaxRounding\n{\n    public const int SCALE = 2;\n}\n"], 'Extract TaxRounding');

        $linked = self::assertToolResult($mcp, $mcp->callTool('link_commits', ['commitSha' => $fix, 'requestIds' => [$requestId]]), 'link_commits');
        $ready = self::assertToolResult($mcp, $mcp->callTool('mark_ready_for_review', ['id' => $requestId, 'note' => 'Extracted TaxRounding. The billing tests pass.']), 'mark_ready_for_review');
        self::assertSame('pending', $linked['state']);
        self::assertSame(['ready_for_review', 'user', 0], [$ready['request']['status'], $ready['request']['awaiting'], $ready['request']['pendingCommitCount']]);

        $verified = self::requestCommand($client, $requestId, 'verify', ['note' => 'Looks right.']);
        self::assertSame(['verified', 'none', 'fixed'], [$verified['request']['status'], $verified['request']['awaiting'], $verified['request']['resolution']]);

        $log = self::eventLog($user, $reviewId);
        $agent = Actor::agent($user->getId(), self::AGENT_NAME);
        $userUpdates = self::assertApiResponse(self::apiRequest($client, 'GET', "/api/v1/reviews/{$reviewId}/updates?cursor=0&includeOwn=true"), 200, 'updates-page.schema.json');
        $agentUpdates = self::assertToolResult($mcp, $mcp->callTool('get_updates', ['review' => $reviewId, 'cursor' => 0]), 'get_updates');
        self::assertSame(
            ['review.started', 'request.opened', 'request.claimed', 'commit.linked', 'commit.found', 'request.ready_for_review', 'request.verified'],
            array_values(self::typesBySeq($log)),
        );
        self::assertSame(self::typesBySeq($log), self::pageTypesBySeq($userUpdates));
        self::assertSame(self::typesBySeq(array_values(array_filter($log, static fn (RecordedEvent $event): bool => !$event->actor->equals($agent)))), self::pageTypesBySeq($agentUpdates));
    }

    /**
     * @param list<RecordedEvent> $events
     *
     * @return array<int, string> Type by seq
     */
    private static function typesBySeq(array $events): array
    {
        $types = [];

        foreach ($events as $event) {
            $types[$event->seq] = $event->event::type()->value;
        }

        return $types;
    }

    /**
     * The union of reviewEvents and all newEvents, without duplicates.
     *
     * @param array<string, mixed> $page An UpdatesPage
     *
     * @return array<int, string> Type by seq
     */
    private static function pageTypesBySeq(array $page): array
    {
        self::assertFalse($page['hasMore']);
        self::assertIsArray($page['requests']);
        self::assertIsArray($page['reviewEvents']);
        $types = [];

        foreach ([$page['reviewEvents'], ...array_column($page['requests'], 'newEvents')] as $events) {
            foreach ($events as $event) {
                $types[$event['seq']] = $event['type'];
            }
        }

        ksort($types);

        return $types;
    }
}
