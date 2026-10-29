<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Tests\Support\Api\AgentApiFixtures;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\DraftApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\GitHub\GitHubPullRequests;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Decision D85: `delivery` of D5 and Q15, and the effective value of V3, Q3, and get_request.
 */
final class DeliveryTest extends WebTestCase
{
    use AgentApiFixtures;
    use ApiFixtures;
    use DraftApiFixtures;
    use EnvironmentVariables;
    use GitHubPullRequests;
    use GitHubRows;
    use JsonApiAssertions;
    use LocalSources;
    use McpAssertions;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const string NO_PULL_REQUESTS = 'This repository has no pull requests.';

    private KernelBrowser $client;

    private User $user;

    private string $secret;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        [, $this->secret] = self::storeToken($this->user);
    }

    #[TestDox('Submit feedback stores the delivery, a submission without one keeps it, and a projection rebuild keeps it')]
    public function testSubmitFeedbackStoresTheDelivery(): void
    {
        $reviewId = self::startCodeReview($this->client);
        $chosen = ['workspace' => 'worktree', 'pullRequest' => false];
        self::assertSame(['workspace' => null, 'pullRequest' => null], $this->review($reviewId)['delivery']);

        $this->submitFeedback($reviewId, ['delivery' => $chosen]);
        $this->submitFeedback($reviewId, ['summary' => 'Second pass.']);

        self::assertSame([$chosen, ['pullRequests' => false]], [$this->review($reviewId)['delivery'], $this->review($reviewId)['capabilities']]);
        $rebuild = new CommandTester(new Application(self::$kernel ?? self::fail('No kernel.'))->find('app:projections:rebuild'));
        $rebuild->execute([]);
        $rebuild->assertCommandIsSuccessful();
        self::assertSame($chosen, $this->review($reviewId)['delivery']);
    }

    #[TestDox('Q3 and get_request show the pull request choice of the approval over that of the review, and a reopen clears it')]
    public function testApprovalChoiceWinsUntilAReopen(): void
    {
        [$reviewId, $requestId] = self::claimedGitHubRequest($this->client, $this->user, $this->secret);
        $this->submitFeedback((string) $reviewId, ['delivery' => ['workspace' => 'new_branch', 'pullRequest' => true]], null);
        $proposed = self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$requestId}/propose", ['plan' => 'Extract `TaxRounding`.']);

        self::requestCommand($this->client, $requestId, 'approve-proposal', ['proposalEventId' => $proposed['request']['proposal']['eventId'], 'pullRequest' => false]);

        $declined = ['workspace' => 'new_branch', 'pullRequest' => false];
        $mcp = new McpTestClient($this->client, $this->secret);
        self::assertSame([$declined, ['pullRequests' => true]], [self::readRequest($this->client, $requestId)['delivery'], self::readRequest($this->client, $requestId)['capabilities']]);
        self::assertSame($declined, self::assertToolResult($mcp, $mcp->callTool('get_request', ['id' => $requestId]), 'get_request')['delivery']);

        self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$requestId}/ready", ['note' => 'Extracted TaxRounding.']);
        self::requestCommand($this->client, $requestId, 'reopen', ['note' => 'Round the shipping totals too.']);

        self::assertSame(['workspace' => 'new_branch', 'pullRequest' => true], self::readRequest($this->client, $requestId)['delivery']);
    }

    #[TestDox('A local repository refuses pullRequest true on D5 and on Q15, and an unknown workspace on D5, and nothing changes')]
    public function testLocalRepositoryRefusesAPullRequest(): void
    {
        $reviewId = self::startCodeReview($this->client);
        $requestId = (string) self::openRequest($this->client, $reviewId)['id'];
        self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$requestId}/claim");
        $proposed = self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$requestId}/propose", ['plan' => 'Extract `TaxRounding`.']);
        self::createDraft($this->client, $reviewId);
        $submit = "/api/v1/reviews/{$reviewId}/drafts/submit";

        $submitted = self::assertApiError(self::apiRequest($this->client, 'POST', $submit, ['delivery' => ['workspace' => null, 'pullRequest' => true]]), 422, 'invalid_input');
        $approved = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/requests/{$requestId}/approve-proposal", ['proposalEventId' => $proposed['request']['proposal']['eventId'], 'pullRequest' => true]), 422, 'invalid_input');
        self::assertApiError(self::apiRequest($this->client, 'POST', $submit, ['delivery' => ['workspace' => 'elsewhere']]), 422, 'invalid_input');

        self::assertSame([['field' => 'delivery.pullRequest', 'message' => self::NO_PULL_REQUESTS]], $submitted['details']['violations'] ?? null);
        self::assertSame([['field' => 'pullRequest', 'message' => self::NO_PULL_REQUESTS]], $approved['details']['violations'] ?? null);
        self::assertCount(1, self::listDrafts($this->client, $reviewId));
        self::assertSame('pending', self::readRequest($this->client, $requestId)['proposal']['state']);
    }

    /**
     * @return array<string, mixed> The Review
     */
    private function review(string $reviewId): array
    {
        return self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$reviewId}"), 200, 'review.schema.json');
    }

    /**
     * Adds one draft and submits it with D5.
     *
     * @param array<string, mixed>      $body
     * @param array<string, mixed>|null $anchor
     */
    private function submitFeedback(string $reviewId, array $body, ?array $anchor = ['kind' => 'file', 'path' => 'README.md']): void
    {
        self::createDraft($this->client, $reviewId, $anchor);
        self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$reviewId}/drafts/submit", $body), 201, 'batch-submission.schema.json');
    }
}
