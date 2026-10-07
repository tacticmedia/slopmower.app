<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

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
 * Q14 to Q16. The user opened a request with requireApproval, and the agent claimed it through REST.
 */
final class ProposalTest extends WebTestCase
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

    private const string PLAN = 'Extract `TaxRounding` and call it from `total()`.';

    private const string DIFF = "diff --git a/src/Billing/TaxRounding.php b/src/Billing/TaxRounding.php\n--- a/src/Billing/TaxRounding.php\n+++ b/src/Billing/TaxRounding.php\n@@ -3,3 +3,4 @@\n final class TaxRounding\n {\n+    public const int SCALE = 2;\n }\n";

    private KernelBrowser $client;

    private string $secret;

    private string $commitSha;

    private string $requestId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $user = self::signIn($this->client);
        $review = self::startReviewThroughApi($this->client, self::connectAndClone($this->client));
        $this->commitSha = (string) $review['commitSha'];
        $opened = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$review['id']}/requests", ['body' => 'Round the tax lines.', 'requireApproval' => true]), 201, 'request.schema.json');
        self::assertSame([true, null], [$opened['requireApproval'], $opened['proposal']]);
        $this->requestId = (string) $opened['id'];
        [, $this->secret] = self::storeToken($user);
        $this->agent('claim');
    }

    #[TestDox('Link is refused until the user approves a proposal with a diff, then link and ready succeed')]
    public function testApprovalUnlocksLinkAndReady(): void
    {
        $this->assertLinkRefused();

        $proposed = $this->agent('propose', ['plan' => self::PLAN, 'diff' => self::DIFF]);
        $eventId = (string) $proposed['events'][0]['eventId'];
        self::assertSame([['eventId' => $eventId, 'state' => 'pending'], 'user'], [$proposed['request']['proposal'], $proposed['request']['awaiting']]);
        self::assertSame(['request.proposed', self::PLAN, self::DIFF], [$proposed['events'][0]['type'], $proposed['events'][0]['body'], $proposed['events'][0]['diff']]);
        $this->assertLinkRefused();

        $approved = self::requestCommand($this->client, $this->requestId, 'approve-proposal', ['proposalEventId' => $eventId]);
        self::assertSame([['eventId' => $eventId, 'state' => 'approved'], 'agent'], [$approved['request']['proposal'], $approved['request']['awaiting']]);

        self::agentRequest($this->client, $this->secret, 'POST', '/api/v1/commit-links', ['commitSha' => $this->commitSha, 'requestIds' => [$this->requestId]], schema: 'link-commits-result.schema.json');
        $ready = $this->agent('ready', ['note' => 'Extracted TaxRounding.']);
        self::assertSame(['ready_for_review', 'approved'], [$ready['request']['status'], $ready['request']['proposal']['state']]);
    }

    #[TestDox('A rejected proposal keeps link refused until the agent proposes again')]
    public function testRejectionNeedsANewProposal(): void
    {
        $first = (string) $this->agent('propose', ['plan' => self::PLAN])['events'][0]['eventId'];

        $rejected = self::requestCommand($this->client, $this->requestId, 'reject-proposal', ['proposalEventId' => $first, 'note' => 'Keep the rounding inside `InvoiceTotals`.']);

        self::assertSame([['eventId' => $first, 'state' => 'rejected'], 'agent'], [$rejected['request']['proposal'], $rejected['request']['awaiting']]);
        self::assertSame(['request.proposal_rejected', 'Keep the rounding inside `InvoiceTotals`.', null], [$rejected['events'][0]['type'], $rejected['events'][0]['body'], $rejected['events'][0]['diff']]);
        $this->assertLinkRefused();
        $second = $this->agent('propose', ['plan' => 'Round inside `InvoiceTotals::tax()`.']);
        self::assertSame('pending', $second['request']['proposal']['state']);
        self::assertNotSame($first, $second['request']['proposal']['eventId']);
    }

    #[TestDox('A decision that names a replaced proposal gives 422 invalid_input')]
    public function testStaleProposalGivesInvalidInput(): void
    {
        $stale = (string) $this->agent('propose', ['plan' => self::PLAN])['events'][0]['eventId'];
        $this->agent('propose', ['plan' => 'Round inside `InvoiceTotals::tax()`.']);

        $error = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/requests/{$this->requestId}/approve-proposal", ['proposalEventId' => $stale]), 422, 'invalid_input');

        self::assertSame([['field' => 'proposalEventId', 'message' => 'Is not the current proposal.']], $error['details']['violations'] ?? null);
    }

    #[TestDox('A decision without a pending proposal gives 409 invalid_transition')]
    public function testDecisionWithoutPendingProposalGivesInvalidTransition(): void
    {
        $error = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/requests/{$this->requestId}/approve-proposal", ['proposalEventId' => '01K6C2EVT00000000000001862']), 409, 'invalid_transition');

        self::assertSame('approve_proposal', $error['details']['action'] ?? null);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed> The RequestCommandResult
     */
    private function agent(string $command, ?array $body = null): array
    {
        return self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$this->requestId}/{$command}", $body);
    }

    private function assertLinkRefused(): void
    {
        $response = self::bearerRequest($this->client, $this->secret, 'POST', '/api/v1/commit-links', ['commitSha' => $this->commitSha, 'requestIds' => [$this->requestId]]);

        self::assertSame('link_commits', self::assertApiError($response, 409, 'invalid_transition')['details']['action'] ?? null);
    }
}
