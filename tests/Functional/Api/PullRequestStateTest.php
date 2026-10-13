<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Message\ReadPullRequestJob;
use App\Tests\Support\Api\AgentApiFixtures;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\GitHub\GitHubPullRequests;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\Live\PublishedUpdates;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;

/**
 * `Request.pullRequests` and the first read of docs/specs/webhooks-and-live-updates.md, "Pull request state".
 */
final class PullRequestStateTest extends WebTestCase
{
    use AgentApiFixtures;
    use ApiFixtures;
    use EnvironmentVariables;
    use GitHubPullRequests;
    use GitHubRows;
    use JsonApiAssertions;
    use LocalSources;
    use McpAssertions;
    use PublishedUpdates;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const string URL = 'https://github.com/acme/billing/pull/42';

    private const array UNKNOWN = [['url' => self::URL, 'state' => 'unknown', 'title' => null]];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    #[TestDox('A link on a GitHub repository queues the read job, whose recorded response sets the state in REST and MCP and publishes pull_request')]
    public function testLinkReadsTheState(): void
    {
        $user = self::signIn($this->client);
        [, $secret] = self::storeToken($user);
        [$reviewId, $requestId] = self::claimedGitHubRequest($this->client, $user, $secret);

        self::linkGitHubPullRequest($this->client, $secret, $requestId);
        $queued = self::queuedReads();
        self::runQueuedMessages();
        // The next request reboots the kernel, which drops the published updates.
        $updates = self::reviewTopicUpdates($user->getTenantId(), $reviewId);

        $rest = self::assertApiResponse(self::bearerRequest($this->client, $secret, 'GET', "/api/v1/requests/{$requestId}"), 200, 'request.schema.json');
        $mcp = new McpTestClient($this->client, $secret);
        $tool = self::assertToolResult($mcp, $mcp->callTool('get_request', ['id' => $requestId]), 'get_request');
        self::assertSame([42], array_map(static fn (ReadPullRequestJob $job): int => $job->number, $queued));
        self::assertSame([['url' => self::URL, 'state' => 'open', 'title' => 'Use Money for invoice totals']], $rest['pullRequests']);
        self::assertSame($rest, $tool);
        self::assertContains(['type' => 'pull_request', 'reviewId' => $reviewId->toString(), 'requestIds' => [$requestId]], $updates);
    }

    #[TestDox('An installation without Pull requests: read requests no token and leaves the state unknown')]
    public function testInstallationWithoutThePermissionRequestsNoToken(): void
    {
        $user = self::signIn($this->client);
        [, $secret] = self::storeToken($user);
        [, $requestId] = self::claimedGitHubRequest($this->client, $user, $secret, ['contents' => 'read', 'metadata' => 'read']);
        self::linkGitHubPullRequest($this->client, $secret, $requestId);
        $gitHubRequestsBefore = self::gitHubRequestCount();

        self::runQueuedMessages();

        self::assertSame($gitHubRequestsBefore, self::gitHubRequestCount());
        self::assertSame(self::UNKNOWN, self::readRequest($this->client, $requestId)['pullRequests']);
    }

    #[TestDox('A local repository queues no read and stays unknown')]
    public function testLocalRepositoryStaysUnknown(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $user = self::signIn($this->client);
        $requestId = (string) self::openRequest($this->client, self::startCodeReview($this->client))['id'];
        [, $secret] = self::storeToken($user);
        self::agentRequest($this->client, $secret, 'POST', "/api/v1/requests/{$requestId}/claim");

        self::linkGitHubPullRequest($this->client, $secret, $requestId);

        self::assertSame([], self::queuedReads());
        self::assertSame(self::UNKNOWN, self::readRequest($this->client, $requestId)['pullRequests']);
    }

    #[TestDox('Two tenants that link one pull request get separate records')]
    public function testTwoTenantsGetSeparateRecords(): void
    {
        $tenantIds = [];

        foreach (['jan', 'eve'] as $login) {
            $user = self::signIn($this->client, $login);
            [, $secret] = self::storeToken($user);
            [, $requestId] = self::claimedGitHubRequest($this->client, $user, $secret);
            self::linkGitHubPullRequest($this->client, $secret, $requestId);
            // Before the next request reboots the kernel, which drops the queue.
            self::runQueuedMessages();
            $tenantIds[] = $user->getTenantId()->toRfc4122();
        }

        $records = self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT tenant_id, number, state FROM pull_request ORDER BY tenant_id');
        sort($tenantIds);
        self::assertSame([[$tenantIds[0], 42, 'open'], [$tenantIds[1], 42, 'open']], array_map(array_values(...), $records));
    }

    /**
     * @return list<ReadPullRequestJob>
     */
    private static function queuedReads(): array
    {
        $messages = array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), [...self::asyncTransport()->getSent()]);

        return array_values(array_filter($messages, static fn (object $message): bool => $message instanceof ReadPullRequestJob));
    }

    private static function gitHubRequestCount(): int
    {
        return self::getContainer()->get('github.client.mock_transport')->getRequestsCount();
    }
}
