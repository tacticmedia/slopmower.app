<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Entity\User;
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
 * The user opened a request through the API; the agent works through MCP.
 */
final class ClaimReleaseToolTest extends WebTestCase
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

    private User $user;

    private McpTestClient $mcp;

    private string $requestId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->requestId = (string) self::openRequest($this->client, self::startCodeReview($this->client))['id'];
        $this->mcp = new McpTestClient($this->client, self::storeToken($this->user)[1]);
    }

    #[TestDox('claim_request sets in_progress')]
    public function testClaimSetsInProgress(): void
    {
        $result = self::assertToolResult($this->mcp, $this->mcp->callTool('claim_request', ['id' => $this->requestId]), 'claim_request');

        self::assertSame(['in_progress', 'agent', self::AGENT_NAME], [$result['request']['status'], $result['request']['awaiting'], $result['request']['claimedBy']['agentName']]);
        self::assertSame(['reply', 'release', 'link_commits', 'mark_ready_for_review', 'mark_wont_do'], $result['allowedActions']);
        self::assertSame([['request.claimed', 'in_progress']], array_map(static fn (array $event): array => [$event['type'], $event['statusAfter']], $result['events']));
    }

    #[TestDox('A repeated claim returns an empty events list')]
    public function testRepeatedClaimReturnsNoEvents(): void
    {
        $first = self::assertToolResult($this->mcp, $this->mcp->callTool('claim_request', ['id' => $this->requestId]), 'claim_request');

        $repeated = self::assertToolResult($this->mcp, $this->mcp->callTool('claim_request', ['id' => $this->requestId]), 'claim_request');

        self::assertSame([], $repeated['events']);
        self::assertSame($first['request'], $repeated['request']);
    }

    #[TestDox("A claim of another agent's request gives isError invalid_transition with claimedBy")]
    public function testClaimOfAnotherAgentsRequestGivesInvalidTransition(): void
    {
        $other = new McpTestClient($this->client, self::storeToken($this->user, name: 'codex desktop')[1]);
        self::assertToolResult($other, $other->callTool('claim_request', ['id' => $this->requestId]), 'claim_request');

        $error = self::assertToolError($this->mcp->callTool('claim_request', ['id' => $this->requestId]), 'invalid_transition');

        self::assertSame(
            ['requestId' => $this->requestId, 'action' => 'claim', 'status' => 'in_progress', 'claimedBy' => ['kind' => 'agent', 'userId' => $this->user->getId()->toString(), 'agentName' => 'codex desktop', 'name' => 'codex desktop', 'avatarUrl' => null], 'allowedActions' => ['reply']],
            $error['details'],
        );
    }

    #[TestDox('release_request restores the status before the claim')]
    public function testReleaseRestoresStatusBeforeClaim(): void
    {
        self::requestCommand($this->client, $this->requestId, 'wont-do', ['reason' => self::WONT_DO_REASON]);
        self::requestCommand($this->client, $this->requestId, 'reopen', ['note' => 'Integer cents still round twice.']);
        self::assertToolResult($this->mcp, $this->mcp->callTool('claim_request', ['id' => $this->requestId]), 'claim_request');

        $result = self::assertToolResult($this->mcp, $this->mcp->callTool('release_request', ['id' => $this->requestId, 'note' => 'The fix needs a schema change.']), 'release_request');

        self::assertSame(['reopened', 'agent', null, null], [$result['request']['status'], $result['request']['awaiting'], $result['request']['claimedBy'], $result['request']['claimedAt']]);
        self::assertSame([['request.released', 'The fix needs a schema change.', 'reopened']], array_map(static fn (array $event): array => [$event['type'], $event['body'], $event['statusAfter']], $result['events']));
    }
}
