<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Application\RequestCommands;
use App\Domain\Actor\Actor;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\Reply;
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
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * docs/specs/mcp.md section 6. The agent claimed a range request and asked a question, which the user answered; a
 * general request is open. REST and MCP use the same token.
 */
final class McpRestParityTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use McpAssertions;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const string PULL_REQUEST = 'https://github.com/acme/billing/pull/42';

    private KernelBrowser $client;

    private McpTestClient $mcp;

    private string $secret;

    private string $reviewId;

    private string $claimedRequestId;

    private string $generalRequestId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $user = self::signIn($this->client);
        $this->reviewId = self::startCodeReview($this->client);
        $this->claimedRequestId = (string) self::openRequest($this->client, $this->reviewId, ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 3, 'endLine' => 5]])['id'];
        $this->generalRequestId = (string) self::openRequest($this->client, $this->reviewId, null)['id'];
        $claimed = RequestId::fromString($this->claimedRequestId);
        self::asAgent($user, static function (RequestCommands $commands, Actor $agent) use ($claimed): void {
            $commands->claim(new Claim($claimed), $agent);
            $commands->reply(new Reply($claimed, 'Should the tax lines use `Money` too?', true), $agent);
        });
        self::requestCommand($this->client, $this->claimedRequestId, 'comments', ['body' => 'Yes, the tax lines too.']);
        [, $this->secret] = self::storeToken($user);
        $this->mcp = new McpTestClient($this->client, $this->secret);
    }

    #[TestDox('list_repositories equals the REST body of R1')]
    public function testListRepositoriesEqualsR1(): void
    {
        $rest = self::assertApiResponse(self::bearerRequest($this->client, $this->secret, 'GET', '/api/v1/repositories'), 200, 'lists.schema.json#/$defs/RepositoryPage');

        $mcp = self::assertToolResult($this->mcp, $this->mcp->callTool('list_repositories'), 'list_repositories');

        self::assertSame($rest, $mcp);
        self::assertIsArray($mcp['items']);
        self::assertCount(1, $mcp['items']);
    }

    #[TestDox('list_reviews equals the REST body of V2 with state=open, for the repository that R1 finds by name')]
    public function testListReviewsEqualsR1ThenV2(): void
    {
        $repositories = self::assertApiResponse(self::bearerRequest($this->client, $this->secret, 'GET', '/api/v1/repositories?name=billing'), 200, 'lists.schema.json#/$defs/RepositoryPage');
        self::assertIsArray($repositories['items']);
        $repositoryId = (string) $repositories['items'][0]['id'];
        $rest = self::assertApiResponse(self::bearerRequest($this->client, $this->secret, 'GET', "/api/v1/repositories/{$repositoryId}/reviews?state=open"), 200, 'lists.schema.json#/$defs/ReviewPage');

        $mcp = self::assertToolResult($this->mcp, $this->mcp->callTool('list_reviews', ['repository' => 'billing']), 'list_reviews');

        self::assertSame($rest, $mcp);
        self::assertIsArray($mcp['items']);
        self::assertSame([$this->reviewId], array_column($mcp['items'], 'id'));
    }

    /**
     * Cursor mode from 0 leaves out the claim and the question of the agent, so it also checks includeOwn=false.
     */
    #[TestDox('get_updates equals the REST body of V10, without a cursor and from cursor 0')]
    public function testGetUpdatesEqualsV10(): void
    {
        foreach (['' => [], '?cursor=0' => ['cursor' => 0]] as $query => $cursor) {
            $rest = self::assertApiResponse(self::bearerRequest($this->client, $this->secret, 'GET', "/api/v1/reviews/{$this->reviewId}/updates{$query}"), 200, 'updates-page.schema.json');

            $mcp = self::assertToolResult($this->mcp, $this->mcp->callTool('get_updates', ['review' => $this->reviewId, ...$cursor]), 'get_updates');

            self::assertSame($rest, $mcp, "updates{$query}");
            self::assertIsArray($mcp['requests']);
            self::assertCount(2, $mcp['requests'], "updates{$query}");
        }
    }

    #[TestDox('get_request equals the REST body of Q3, also for a general request')]
    public function testGetRequestEqualsQ3(): void
    {
        foreach ([$this->claimedRequestId, $this->generalRequestId] as $requestId) {
            $rest = self::assertApiResponse(self::bearerRequest($this->client, $this->secret, 'GET', "/api/v1/requests/{$requestId}"), 200, 'request.schema.json');

            $mcp = self::assertToolResult($this->mcp, $this->mcp->callTool('get_request', ['id' => $requestId]), 'get_request');

            self::assertSame($rest, $mcp, $requestId);
        }

        self::assertNull($mcp['anchor']);
    }

    #[TestDox('list_requests equals the REST body of Q1')]
    public function testListRequestsEqualsQ1(): void
    {
        $rest = self::assertApiResponse(self::bearerRequest($this->client, $this->secret, 'GET', "/api/v1/reviews/{$this->reviewId}/requests"), 200, 'lists.schema.json#/$defs/RequestPage');

        $mcp = self::assertToolResult($this->mcp, $this->mcp->callTool('list_requests', ['review' => $this->reviewId]), 'list_requests');

        self::assertSame($rest, $mcp);
        self::assertSame(2, $mcp['total']);
    }

    /**
     * Each tool works on one request, and its REST endpoint on a twin in the same state, at a frozen time. The bodies
     * differ only in the request ID and in the numbers and IDs of the new events.
     */
    #[TestDox('Each write tool returns the same body as its REST endpoint')]
    public function testWriteToolsEqualRestEndpoints(): void
    {
        self::mockTime('2026-09-29 07:40:00');
        $commit = (string) self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}"), 200, 'review.schema.json')['commitSha'];
        [$doneByMcp, $doneByRest] = [$this->openFileRequest(), $this->openFileRequest()];
        [$releasedByMcp, $releasedByRest] = [$this->openFileRequest(), $this->openFileRequest()];
        $note = 'Extracted TaxRounding.';
        $proposal = ['plan' => 'Extract `TaxRounding`.', 'diff' => "diff --git a/README.md b/README.md\n--- a/README.md\n+++ b/README.md\n@@ -1 +1 @@\n-Billing\n+Billing with `Money`\n"];
        $writes = [
            [$doneByMcp, $doneByRest, 'claim_request', ['id' => $doneByMcp], 'POST', 'claim', null],
            [$doneByMcp, $doneByRest, 'reply_to_request', ['id' => $doneByMcp, 'body' => 'Rounding too?', 'question' => true], 'POST', 'comments', ['body' => 'Rounding too?', 'question' => true]],
            [$doneByMcp, $doneByRest, 'link_commits', ['commitSha' => $commit, 'requestIds' => [$doneByMcp], 'note' => $note], 'POST', '/api/v1/commit-links', ['commitSha' => $commit, 'requestIds' => [$doneByRest], 'note' => $note]],
            [$doneByMcp, $doneByRest, 'link_pull_request', ['url' => self::PULL_REQUEST, 'requestIds' => [$doneByMcp], 'note' => $note], 'POST', '/api/v1/pull-request-links', ['url' => self::PULL_REQUEST, 'requestIds' => [$doneByRest], 'note' => $note]],
            [$doneByMcp, $doneByRest, 'unlink_pull_request', ['url' => self::PULL_REQUEST, 'requestId' => $doneByMcp], 'POST', 'unlink-pull-request', ['url' => self::PULL_REQUEST]],
            [$doneByMcp, $doneByRest, 'unlink_commit', ['commitSha' => $commit, 'requestId' => $doneByMcp], 'DELETE', "commits/{$commit}", null],
            [$doneByMcp, $doneByRest, 'mark_ready_for_review', ['id' => $doneByMcp, 'note' => $note], 'POST', 'ready', ['note' => $note]],
            [$releasedByMcp, $releasedByRest, 'claim_request', ['id' => $releasedByMcp], 'POST', 'claim', null],
            [$releasedByMcp, $releasedByRest, 'propose_solution', ['id' => $releasedByMcp, ...$proposal], 'POST', 'propose', $proposal],
            [$releasedByMcp, $releasedByRest, 'release_request', ['id' => $releasedByMcp, 'note' => 'I stop here.'], 'POST', 'release', ['note' => 'I stop here.']],
            [$releasedByMcp, $releasedByRest, 'mark_wont_do', ['id' => $releasedByMcp, 'reason' => self::WONT_DO_REASON], 'POST', 'wont-do', ['reason' => self::WONT_DO_REASON]],
        ];

        foreach ($writes as [$mcpRequestId, $restRequestId, $tool, $arguments, $method, $path, $body]) {
            $mcp = self::assertToolResult($this->mcp, $this->mcp->callTool($tool, $arguments), $tool);
            $uri = str_starts_with($path, '/') ? $path : "/api/v1/requests/{$restRequestId}/{$path}";
            $rest = self::assertApiResponse(self::bearerRequest($this->client, $this->secret, $method, $uri, $body), 'comments' === $path ? 201 : 200, self::TOOL_SCHEMAS[$tool]);

            self::assertSame(self::canonical($mcp, $mcpRequestId, $mcpRequestId), self::canonical($rest, $restRequestId, $mcpRequestId), $tool);
        }
    }

    private function openFileRequest(): string
    {
        return (string) self::openRequest($this->client, $this->reviewId)['id'];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> The body with $requestId as $asRequestId, and the event numbers and IDs masked
     */
    private static function canonical(array $body, string $requestId, string $asRequestId): array
    {
        /** @var array<string, mixed> $canonical */
        $canonical = json_decode(str_replace($requestId, $asRequestId, json_encode($body, \JSON_THROW_ON_ERROR)), true, flags: \JSON_THROW_ON_ERROR);
        array_walk_recursive($canonical, static function (mixed &$value, int|string $key): void {
            $value = \in_array($key, ['seq', 'eventId', 'commentId', 'lastEventSeq'], true) ? "<{$key}>" : $value;
        });

        return $canonical;
    }
}
