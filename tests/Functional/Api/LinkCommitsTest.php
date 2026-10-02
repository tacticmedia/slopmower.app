<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\LinkCommits;
use App\Entity\User;
use App\Git\PinRefs;
use App\Message\FetchRepositoryJob;
use App\Tests\Support\Api\AgentApiFixtures;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Q7 and link_commits. The agent claimed two requests of a review at the commit "Add invoice totals".
 */
final class LinkCommitsTest extends WebTestCase
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

    private GitRepositoryBuilder $source;

    private string $reviewCommit;

    private KernelBrowser $client;

    private User $user;

    private string $repositoryId;

    private string $secret;

    private McpTestClient $mcp;

    private string $first;

    private string $second;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        $this->reviewCommit = $this->source->commit(self::codeFiles(), 'Add invoice totals');
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->repositoryId = self::connectAndClone($this->client);
        $reviewId = (string) self::startReviewThroughApi($this->client, $this->repositoryId)['id'];
        $this->first = (string) self::openRequest($this->client, $reviewId)['id'];
        $this->second = (string) self::openRequest($this->client, $reviewId)['id'];
        [, $this->secret] = self::storeToken($this->user);
        $this->mcp = new McpTestClient($this->client, $this->secret);
        $this->claim($this->first);
        $this->claim($this->second);
    }

    #[TestDox('An available commit gets the state available and a pin')]
    public function testAvailableCommitGetsPin(): void
    {
        $result = $this->link($this->reviewCommit, [$this->first], 'One refactoring covers both.');

        self::assertSame(['available', $this->reviewCommit], [$result['state'], $result['commitSha']]);
        self::assertSame([[1, 0]], array_map(static fn (array $request): array => [$request['commitCount'], $request['pendingCommitCount']], $result['requests']));
        self::assertSame([['commit.linked', [$this->first], 'One refactoring covers both.']], array_map(static fn (array $event): array => [$event['type'], $event['requestIds'], $event['body']], $result['events']));
        self::assertSame([], $this->queuedFetches());
        self::assertSame($this->reviewCommit, self::pins($this->user, $this->repositoryId)[PinRefs::COMMITS.$this->reviewCommit] ?? null);
        $link = self::readRequest($this->client, $this->first)['commits'][0];
        self::assertSame(['available', 'Add invoice totals', $link['linkedAt']], [$link['state'], $link['subject'], $link['foundAt']]);
        self::assertIsString($link['committedAt']);
    }

    #[TestDox('A missing commit gets the state pending and queues a fetch')]
    public function testMissingCommitIsPendingAndQueuesFetch(): void
    {
        $fix = $this->source->switch('fix')->commit(['src/Billing/TaxRounding.php' => "<?php\n\nfinal class TaxRounding\n{\n    public const int SCALE = 2;\n}\n"], 'Extract TaxRounding');

        $result = $this->link($fix, [$this->first]);

        self::assertSame(['pending', [[1, 1]]], [$result['state'], array_map(static fn (array $request): array => [$request['commitCount'], $request['pendingCommitCount']], $result['requests'])]);
        $fetches = $this->queuedFetches();
        self::assertCount(1, $fetches);
        self::assertSame(LinkCommits::FETCH_DELAY_MILLISECONDS, $fetches[0]->last(DelayStamp::class)?->getDelay());
        self::assertArrayNotHasKey(PinRefs::COMMITS.$fix, self::pins($this->user, $this->repositoryId));
    }

    #[TestDox('A second pending link while the fetch is queued dispatches no second fetch')]
    public function testSecondPendingLinkReusesQueuedFetch(): void
    {
        $this->source->switch('fix');
        $this->link($this->source->commit(['CHANGELOG.md' => "First\n"], 'First fix'), [$this->first]);
        self::assertCount(1, $this->queuedFetches());

        $this->link($this->source->commit(['CHANGELOG.md' => "Second\n"], 'Second fix'), [$this->second]);

        // The client resets the transport before each request, so this holds the jobs of the second link only.
        self::assertSame([], $this->queuedFetches());
    }

    #[TestDox('A short SHA gives -32602 over MCP and 422 over REST')]
    public function testShortShaIsRefused(): void
    {
        $shortSha = substr($this->reviewCommit, 0, 12);

        self::assertJsonRpcError($this->mcp->callToolResponse('link_commits', ['commitSha' => $shortSha, 'requestIds' => [$this->first]]), -32602);
        $error = self::assertApiError(self::bearerRequest($this->client, $this->secret, 'POST', '/api/v1/commit-links', ['commitSha' => $shortSha, 'requestIds' => [$this->first]]), 422, 'invalid_input');

        self::assertSame('commitSha', $error['details']['violations'][0]['field'] ?? null);
    }

    #[TestDox('A repeated request ID in another case gives 422 before an unknown ID gives 404')]
    public function testRepeatedRequestIdGivesInvalidInputBeforeNotFound(): void
    {
        $body = ['commitSha' => $this->reviewCommit, 'requestIds' => [$this->first, strtolower($this->first), '01K6B3P2A9QRSTVWXYZ0123456']];

        $error = self::assertApiError(self::bearerRequest($this->client, $this->secret, 'POST', '/api/v1/commit-links', $body), 422, 'invalid_input');

        self::assertSame('requestIds', $error['details']['violations'][0]['field'] ?? null);
    }

    #[TestDox('One commit covers two requests')]
    public function testOneCommitCoversTwoRequests(): void
    {
        $result = $this->link($this->reviewCommit, [$this->first, $this->second]);

        self::assertSame([[$this->first, 1], [$this->second, 1]], array_map(static fn (array $request): array => [$request['id'], $request['commitCount']], $result['requests']));
        self::assertSame([['commit.linked', [$this->first, $this->second], null]], array_map(static fn (array $event): array => [$event['type'], $event['requestIds'], $event['statusAfter']], $result['events']));
    }

    #[TestDox('Requests of two reviews give invalid_input')]
    public function testRequestsOfTwoReviewsGiveInvalidInput(): void
    {
        $otherReviewId = (string) self::startReviewThroughApi($this->client, $this->repositoryId)['id'];
        $otherReviewRequest = (string) self::openRequest($this->client, $otherReviewId)['id'];
        $this->claim($otherReviewRequest);

        $error = self::assertToolError($this->mcp->callTool('link_commits', ['commitSha' => $this->reviewCommit, 'requestIds' => [$this->first, $otherReviewRequest]]), 'invalid_input');

        self::assertSame('requestIds.1', $error['details']['violations'][0]['field'] ?? null);
    }

    #[TestDox('A request that the caller does not hold gives invalid_transition and writes nothing')]
    public function testRequestNotHeldGivesInvalidTransition(): void
    {
        $notHeld = (string) self::openRequest($this->client, (string) self::readRequest($this->client, $this->first)['reviewId'])['id'];

        $error = self::assertApiError(self::bearerRequest($this->client, $this->secret, 'POST', '/api/v1/commit-links', ['commitSha' => $this->reviewCommit, 'requestIds' => [$this->first, $notHeld]]), 409, 'invalid_transition');

        self::assertSame(['requestId' => $notHeld, 'action' => 'link_commits', 'status' => 'open'], array_intersect_key($error['details'] ?? [], array_flip(['requestId', 'action', 'status'])));
        self::assertSame([], self::readRequest($this->client, $this->first)['commits']);
        self::assertArrayNotHasKey(PinRefs::COMMITS.$this->reviewCommit, self::pins($this->user, $this->repositoryId));
    }

    #[TestDox('Linking the same pair again writes no event')]
    public function testLinkingSamePairAgainWritesNoEvent(): void
    {
        $this->link($this->reviewCommit, [$this->first]);

        $again = self::agentRequest($this->client, $this->secret, 'POST', '/api/v1/commit-links', ['commitSha' => $this->reviewCommit, 'requestIds' => [$this->first]], schema: 'link-commits-result.schema.json');

        self::assertSame(['available', [], 1], [$again['state'], $again['events'], $again['requests'][0]['commitCount']]);
    }

    private function claim(string $requestId): void
    {
        self::assertToolResult($this->mcp, $this->mcp->callTool('claim_request', ['id' => $requestId]), 'claim_request');
    }

    /**
     * @param list<string> $requestIds
     *
     * @return array<string, mixed> The LinkCommitsResult
     */
    private function link(string $commitSha, array $requestIds, ?string $note = null): array
    {
        return self::assertToolResult($this->mcp, $this->mcp->callTool('link_commits', ['commitSha' => $commitSha, 'requestIds' => $requestIds, 'note' => $note]), 'link_commits');
    }

    /**
     * The transport holds a delayed envelope back from get().
     *
     * @return list<Envelope> The fetch jobs sent so far, which the test never runs
     */
    private function queuedFetches(): array
    {
        return array_values(array_filter(self::asyncTransport()->getSent(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof FetchRepositoryJob));
    }
}
