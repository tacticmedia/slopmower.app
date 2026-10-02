<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Git\PinRefs;
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

/**
 * Q10 and mark_ready_for_review. The agent claimed a request; the queued fetch of a pending link never runs.
 */
final class MarkReadyTest extends WebTestCase
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

    private const string NOTE = 'Extracted TaxRounding. The billing tests pass.';

    private GitRepositoryBuilder $source;

    private KernelBrowser $client;

    private User $user;

    private string $secret;

    private McpTestClient $mcp;

    private string $repositoryId;

    private string $requestId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        $this->source->commit(self::codeFiles());
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->repositoryId = self::connectAndClone($this->client);
        $this->requestId = (string) self::openRequest($this->client, (string) self::startReviewThroughApi($this->client, $this->repositoryId)['id'])['id'];
        [, $this->secret] = self::storeToken($this->user);
        $this->mcp = new McpTestClient($this->client, $this->secret);
        self::assertToolResult($this->mcp, $this->mcp->callTool('claim_request', ['id' => $this->requestId]), 'claim_request');
    }

    #[TestDox('Runs one fetch, the listener writes commit.found once, then the request is ready')]
    public function testFetchesOnceThenIsReady(): void
    {
        $fix = $this->linkFix();
        $fetches = self::countFetches($this->client);

        $result = self::assertToolResult($this->mcp, $this->mcp->callTool('mark_ready_for_review', ['id' => $this->requestId, 'note' => self::NOTE]), 'mark_ready_for_review');

        self::assertSame(['ready_for_review', 'user', 1, 0], [$result['request']['status'], $result['request']['awaiting'], $result['request']['commitCount'], $result['request']['pendingCommitCount']]);
        self::assertSame(1, $fetches());
        $found = array_values(array_filter(self::readRequest($this->client, $this->requestId)['thread'], static fn (array $entry): bool => 'commit.found' === $entry['type']));
        self::assertSame([['system', $fix]], array_map(static fn (array $entry): array => [$entry['actor']['kind'], $entry['commitSha']], $found));
        self::assertSame($fix, self::pins($this->user, $this->repositoryId)[PinRefs::COMMITS.$fix] ?? null);
    }

    #[TestDox('The result events hold only request.ready_for_review')]
    public function testResultEventsHoldOnlyReadyForReview(): void
    {
        $this->linkFix();

        $result = self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$this->requestId}/ready", ['note' => self::NOTE]);

        self::assertSame([['request.ready_for_review', self::NOTE, 'ready_for_review']], array_map(static fn (array $event): array => [$event['type'], $event['body'], $event['statusAfter']], $result['events']));
    }

    #[TestDox('A commit that is still missing gives commits_not_available with its SHA')]
    public function testMissingCommitGivesCommitsNotAvailable(): void
    {
        $missing = str_repeat('ef', 20);
        self::assertToolResult($this->mcp, $this->mcp->callTool('link_commits', ['commitSha' => $missing, 'requestIds' => [$this->requestId]]), 'link_commits');

        $error = self::assertToolError($this->mcp->callTool('mark_ready_for_review', ['id' => $this->requestId, 'note' => self::NOTE]), 'commits_not_available');

        self::assertSame(['pendingCommits' => [$missing]], $error['details']);
        self::assertSame('in_progress', self::readRequest($this->client, $this->requestId)['status']);
    }

    #[TestDox('Zero links are allowed')]
    public function testZeroLinksAreAllowed(): void
    {
        $result = self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$this->requestId}/ready", ['note' => 'No code change: the totals already use integer cents.']);

        self::assertSame(['ready_for_review', 0], [$result['request']['status'], $result['request']['commitCount']]);
    }

    #[TestDox('A request that is not in progress gets invalid_transition without a fetch')]
    public function testRequestNotInProgressGetsInvalidTransitionWithoutFetch(): void
    {
        $this->linkFix();
        self::assertToolResult($this->mcp, $this->mcp->callTool('release_request', ['id' => $this->requestId]), 'release_request');
        $fetches = self::countFetches($this->client);

        $error = self::assertToolError($this->mcp->callTool('mark_ready_for_review', ['id' => $this->requestId, 'note' => self::NOTE]), 'invalid_transition');

        self::assertSame(['action' => 'mark_ready_for_review', 'status' => 'open'], array_intersect_key($error['details'] ?? [], array_flip(['action', 'status'])));
        self::assertSame(0, $fetches());
    }

    /**
     * Commits on a branch of the source after the clone, and links the commit while it is pending.
     *
     * @return string The commit SHA
     */
    private function linkFix(): string
    {
        $fix = $this->source->switch('fix')->commit(['src/Billing/TaxRounding.php' => "<?php\n\nfinal class TaxRounding\n{\n    public const int SCALE = 2;\n}\n"], 'Extract TaxRounding');
        $result = self::assertToolResult($this->mcp, $this->mcp->callTool('link_commits', ['commitSha' => $fix, 'requestIds' => [$this->requestId]]), 'link_commits');
        self::assertSame('pending', $result['state']);

        return $fix;
    }
}
