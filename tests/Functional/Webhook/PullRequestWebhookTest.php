<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhook;

use App\Domain\Identity\ReviewId;
use App\Entity\User;
use App\Message\GitHubPullRequestJob;
use App\Tests\Support\Api\AgentApiFixtures;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\GitHub\GitHubPullRequests;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\GitHubWebhooks;
use App\Tests\Support\Live\PublishedUpdates;
use App\Tests\Support\Messenger\QueuedMessages;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;

/**
 * The `pull_request` event. The agent linked pull request 42 of acme/billing, and the first read found it open, updated
 * at 2026-09-29T07:46:00Z.
 */
final class PullRequestWebhookTest extends WebTestCase
{
    use AgentApiFixtures;
    use ApiFixtures;
    use GitHubPullRequests;
    use GitHubRows;
    use GitHubWebhooks;
    use JsonApiAssertions;
    use PublishedUpdates;
    use QueuedMessages;
    use ResetDatabase;

    private const string URL = 'https://github.com/acme/billing/pull/42';

    private KernelBrowser $client;

    private User $user;

    private ReviewId $reviewId;

    private string $requestId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        [, $secret] = self::storeToken($this->user);
        [$this->reviewId, $this->requestId] = self::claimedGitHubRequest($this->client, $this->user, $secret);
        self::linkGitHubPullRequest($this->client, $secret, $this->requestId);
        self::runQueuedMessages();
    }

    #[TestDox('closed with merged: true updates the record of a linked number and publishes pull_request')]
    public function testMergedUpdatesTheRecord(): void
    {
        self::deliverFixture($this->client, 'pull_request.closed');
        self::runQueuedMessages();

        self::assertSame([['type' => 'pull_request', 'reviewId' => $this->reviewId->toString(), 'requestIds' => [$this->requestId]]], $this->pullRequestUpdates());
        self::assertSame([['url' => self::URL, 'state' => 'merged', 'title' => 'Use Money for invoice totals']], $this->pullRequests());
    }

    #[TestDox('A number without a record queues no job')]
    public function testNumberWithoutARecordQueuesNoJob(): void
    {
        self::deliverFixture($this->client, 'pull_request.closed', ['number' => 43, 'pull_request' => ['number' => 43]]);
        self::runQueuedMessages();

        self::assertSame([], array_filter([...self::asyncTransport()->getSent()], static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof GitHubPullRequestJob));
    }

    #[TestDox('A payload with an older updated_at than the record changes nothing')]
    public function testOlderPayloadChangesNothing(): void
    {
        self::deliverFixture($this->client, 'pull_request.closed', ['pull_request' => ['updated_at' => '2026-09-29T07:45:59Z']]);
        self::runQueuedMessages();

        self::assertSame([], $this->pullRequestUpdates());
        self::assertSame('open', $this->pullRequests()[0]['state'] ?? null);
    }

    /**
     * @return list<array<string, mixed>> `Request.pullRequests` of Q3
     */
    private function pullRequests(): array
    {
        $request = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/requests/{$this->requestId}"), 200, 'request.schema.json');
        self::assertIsList($request['pullRequests']);

        return $request['pullRequests'];
    }

    /**
     * The updates since the last kernel reboot, which each request causes.
     *
     * @return list<array<string, mixed>>
     */
    private function pullRequestUpdates(): array
    {
        return array_values(array_filter(self::reviewTopicUpdates($this->user->getTenantId(), $this->reviewId), static fn (array $update): bool => 'pull_request' === $update['type']));
    }
}
