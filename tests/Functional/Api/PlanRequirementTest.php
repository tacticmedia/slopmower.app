<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Security\CurrentActor;
use App\Tests\Support\Api\AgentApiFixtures;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Q18. The agent claimed the request through REST.
 */
final class PlanRequirementTest extends WebTestCase
{
    use AgentApiFixtures;
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private KernelBrowser $client;

    private string $secret;

    private string $reviewId;

    private string $commitSha;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $user = self::signIn($this->client);
        $review = self::startReviewThroughApi($this->client, self::connectAndClone($this->client));
        $this->reviewId = (string) $review['id'];
        $this->commitSha = (string) $review['commitSha'];
        [, $this->secret] = self::storeToken($user);
    }

    #[TestDox('Switching on while in_progress refuses link_commits until the user approves a proposal')]
    public function testSwitchingOnRefusesLinkUntilApproval(): void
    {
        $requestId = $this->claimedRequest(false);

        $required = $this->setPlanRequirement($requestId, true);

        self::assertSame([true, 'agent'], [$required['request']['requireApproval'], $required['request']['awaiting']]);
        self::assertSame([['request.plan_required', null]], array_map(static fn (array $event): array => [$event['type'], $event['body']], $required['events']));
        self::assertStringEndsWith('the request asks for a plan first, so propose a solution.', $this->linkRefused($requestId)['message']);
        $proposed = self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$requestId}/propose", ['plan' => 'Extract `TaxRounding`.']);
        self::requestCommand($this->client, $requestId, 'approve-proposal', ['proposalEventId' => $proposed['request']['proposal']['eventId']]);
        $this->link($requestId);
    }

    #[TestDox('Switching off opens the gate without a proposal')]
    public function testSwitchingOffOpensTheGate(): void
    {
        $requestId = $this->claimedRequest(true);
        $this->linkRefused($requestId);

        $waived = $this->setPlanRequirement($requestId, false);

        self::assertSame([false, null], [$waived['request']['requireApproval'], $waived['request']['proposal']]);
        self::assertSame([['request.plan_waived', null]], array_map(static fn (array $event): array => [$event['type'], $event['body']], $waived['events']));
        $this->link($requestId);
    }

    #[TestDox('The current value writes no event')]
    public function testCurrentValueWritesNoEvent(): void
    {
        $requestId = $this->claimedRequest(true);
        $before = self::readRequest($this->client, $requestId);

        $result = $this->setPlanRequirement($requestId, true);

        self::assertSame([[], true, $before['lastEventSeq']], [$result['events'], $result['request']['requireApproval'], $result['request']['lastEventSeq']]);
    }

    #[TestDox('A bearer token gets 403 forbidden')]
    public function testBearerTokenGetsForbidden(): void
    {
        $requestId = $this->claimedRequest(false);

        $response = self::bearerRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$requestId}/plan-requirement", ['requireApproval' => true]);

        self::assertSame(CurrentActor::SESSION_ONLY, self::assertApiError($response, 403, 'forbidden')['message']);
        self::assertFalse(self::readRequest($this->client, $requestId)['requireApproval']);
    }

    private function claimedRequest(bool $requireApproval): string
    {
        $opened = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/requests", ['body' => 'Round the tax lines.', 'requireApproval' => $requireApproval]), 201, 'request.schema.json');
        $requestId = (string) $opened['id'];
        self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$requestId}/claim");

        return $requestId;
    }

    /**
     * @return array<string, mixed> The RequestCommandResult
     */
    private function setPlanRequirement(string $requestId, bool $requireApproval): array
    {
        return self::requestCommand($this->client, $requestId, 'plan-requirement', ['requireApproval' => $requireApproval]);
    }

    private function link(string $requestId): void
    {
        self::agentRequest($this->client, $this->secret, 'POST', '/api/v1/commit-links', ['commitSha' => $this->commitSha, 'requestIds' => [$requestId]], schema: 'link-commits-result.schema.json');
    }

    /**
     * @return array<string, mixed> The error
     */
    private function linkRefused(string $requestId): array
    {
        $response = self::bearerRequest($this->client, $this->secret, 'POST', '/api/v1/commit-links', ['commitSha' => $this->commitSha, 'requestIds' => [$requestId]]);
        $error = self::assertApiError($response, 409, 'invalid_transition');
        self::assertSame('link_commits', $error['details']['action'] ?? null);

        return $error;
    }
}
