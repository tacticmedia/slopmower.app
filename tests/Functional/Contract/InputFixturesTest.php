<?php

declare(strict_types=1);

namespace App\Tests\Functional\Contract;

use App\Tests\Support\Api\AgentApiFixtures;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\DraftApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Contract\SchemaValidator;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Sends each request body fixture of docs/specs/README.md section 11 to its endpoint. The fixture IDs stand for rows
 * of the test: the review repository, the review, a first request, a second request, and the proposal of the agent on
 * the first request. `{targetReviewId}` is a second review of the repository.
 */
final class InputFixturesTest extends WebTestCase
{
    use AgentApiFixtures;
    use ApiFixtures;
    use ClockSensitiveTrait;
    use DraftApiFixtures;
    use EnvironmentVariables;
    use GitHubRows;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const string FIXTURE_DIRECTORY = __DIR__.'/../../../docs/specs/fixtures';

    private const string FIXTURE_REPOSITORY_ID = '01K6B2Z0R3P0S1T2V3W4X5Y6Z7';

    private const string FIXTURE_REVIEW_ID = '01K6B3A1B2C3D4E5F6G7H8J9KM';

    private const string FIXTURE_REQUEST_ID = '01K6B3P2A9QRSTVWXYZ0123456';

    private const string FIXTURE_SECOND_REQUEST_ID = '01K6B3P2A9QRSTVWXYZ0123457';

    private const string FIXTURE_PROPOSAL_EVENT_ID = '01K6C2EVT00000000000001862';

    private const string RESULT = 'request-command-result.schema.json';

    /**
     * Per fixture: its schema, the endpoint, the success status and schema, whether an agent token calls, the fixture
     * requests that the agent claims first, whether a draft must exist, whether the agent proposes on the first
     * request, whether the agent links the fixture URL to the first request, and whether the agent suggested the first
     * request.
     */
    private const array CASES = [
        'inputs.accept-suggestion.json' => ['schema' => 'inputs.schema.json#/$defs/AcceptSuggestionInput', 'method' => 'POST', 'path' => '/api/v1/requests/{requestId}/accept-suggestion', 'status' => 200, 'response' => self::RESULT, 'suggested' => true],
        'inputs.approve-proposal.json' => ['schema' => 'inputs.schema.json#/$defs/ApproveProposalInput', 'method' => 'POST', 'path' => '/api/v1/requests/{requestId}/approve-proposal', 'status' => 200, 'response' => self::RESULT, 'claimed' => [self::FIXTURE_REQUEST_ID], 'proposed' => true],
        'inputs.carry-over.json' => ['schema' => 'inputs.schema.json#/$defs/CarryOverInput', 'method' => 'POST', 'path' => '/api/v1/reviews/{targetReviewId}/carry-over', 'status' => 200, 'response' => 'carry-over-result.schema.json'],
        'inputs.close-review.json' => ['schema' => 'inputs.schema.json#/$defs/CloseReviewInput', 'method' => 'POST', 'path' => '/api/v1/reviews/{reviewId}/close', 'status' => 200, 'response' => 'review.schema.json'],
        'inputs.comment.json' => ['schema' => 'inputs.schema.json#/$defs/CommentInput', 'method' => 'POST', 'path' => '/api/v1/requests/{requestId}/comments', 'status' => 201, 'response' => self::RESULT, 'bearer' => true],
        'inputs.connect-github.json' => ['schema' => 'inputs.schema.json#/$defs/ConnectRepositoryInput', 'method' => 'POST', 'path' => '/api/v1/repositories', 'status' => 201, 'response' => 'repository.schema.json'],
        'inputs.connect-local.json' => ['schema' => 'inputs.schema.json#/$defs/ConnectRepositoryInput', 'method' => 'POST', 'path' => '/api/v1/repositories', 'status' => 201, 'response' => 'repository.schema.json'],
        'inputs.create-draft-general-request.json' => ['schema' => 'inputs.schema.json#/$defs/CreateDraftInput', 'method' => 'POST', 'path' => '/api/v1/reviews/{reviewId}/drafts', 'status' => 201, 'response' => 'draft.schema.json'],
        'inputs.create-draft-new-request.json' => ['schema' => 'inputs.schema.json#/$defs/CreateDraftInput', 'method' => 'POST', 'path' => '/api/v1/reviews/{reviewId}/drafts', 'status' => 201, 'response' => 'draft.schema.json'],
        'inputs.create-draft-reply.json' => ['schema' => 'inputs.schema.json#/$defs/CreateDraftInput', 'method' => 'POST', 'path' => '/api/v1/reviews/{reviewId}/drafts', 'status' => 201, 'response' => 'draft.schema.json'],
        'inputs.create-token.json' => ['schema' => 'inputs.schema.json#/$defs/CreateTokenInput', 'method' => 'POST', 'path' => '/api/v1/tokens', 'status' => 201, 'response' => 'api-token.schema.json#/$defs/ApiTokenWithSecret'],
        'inputs.dismiss-suggestion.json' => ['schema' => 'inputs.schema.json#/$defs/DismissSuggestionInput', 'method' => 'POST', 'path' => '/api/v1/requests/{requestId}/dismiss-suggestion', 'status' => 200, 'response' => self::RESULT, 'suggested' => true],
        'inputs.link-commits.json' => ['schema' => 'inputs.schema.json#/$defs/LinkCommitsInput', 'method' => 'POST', 'path' => '/api/v1/commit-links', 'status' => 200, 'response' => 'link-commits-result.schema.json', 'bearer' => true, 'claimed' => [self::FIXTURE_REQUEST_ID, self::FIXTURE_SECOND_REQUEST_ID]],
        'inputs.link-pull-request.json' => ['schema' => 'inputs.schema.json#/$defs/LinkPullRequestInput', 'method' => 'POST', 'path' => '/api/v1/pull-request-links', 'status' => 200, 'response' => 'link-pull-request-result.schema.json', 'bearer' => true, 'claimed' => [self::FIXTURE_REQUEST_ID, self::FIXTURE_SECOND_REQUEST_ID]],
        'inputs.open-general-request.json' => ['schema' => 'inputs.schema.json#/$defs/OpenRequestInput', 'method' => 'POST', 'path' => '/api/v1/reviews/{reviewId}/requests', 'status' => 201, 'response' => 'request.schema.json'],
        'inputs.open-request-approval.json' => ['schema' => 'inputs.schema.json#/$defs/OpenRequestInput', 'method' => 'POST', 'path' => '/api/v1/reviews/{reviewId}/requests', 'status' => 201, 'response' => 'request.schema.json'],
        'inputs.open-request.json' => ['schema' => 'inputs.schema.json#/$defs/OpenRequestInput', 'method' => 'POST', 'path' => '/api/v1/reviews/{reviewId}/requests', 'status' => 201, 'response' => 'request.schema.json'],
        'inputs.plan-requirement.json' => ['schema' => 'inputs.schema.json#/$defs/PlanRequirementInput', 'method' => 'POST', 'path' => '/api/v1/requests/{requestId}/plan-requirement', 'status' => 200, 'response' => self::RESULT],
        'inputs.propose-solution.json' => ['schema' => 'inputs.schema.json#/$defs/ProposeSolutionInput', 'method' => 'POST', 'path' => '/api/v1/requests/{requestId}/propose', 'status' => 200, 'response' => self::RESULT, 'bearer' => true, 'claimed' => [self::FIXTURE_REQUEST_ID]],
        'inputs.ready.json' => ['schema' => 'inputs.schema.json#/$defs/RequiredNoteInput', 'method' => 'POST', 'path' => '/api/v1/requests/{requestId}/ready', 'status' => 200, 'response' => self::RESULT, 'bearer' => true, 'claimed' => [self::FIXTURE_REQUEST_ID]],
        'inputs.reject-proposal.json' => ['schema' => 'inputs.schema.json#/$defs/RejectProposalInput', 'method' => 'POST', 'path' => '/api/v1/requests/{requestId}/reject-proposal', 'status' => 200, 'response' => self::RESULT, 'claimed' => [self::FIXTURE_REQUEST_ID], 'proposed' => true],
        'inputs.release.json' => ['schema' => 'inputs.schema.json#/$defs/OptionalNoteInput', 'method' => 'POST', 'path' => '/api/v1/requests/{requestId}/release', 'status' => 200, 'response' => self::RESULT, 'bearer' => true, 'claimed' => [self::FIXTURE_REQUEST_ID]],
        'inputs.start-review.json' => ['schema' => 'inputs.schema.json#/$defs/StartReviewInput', 'method' => 'POST', 'path' => '/api/v1/repositories/{repositoryId}/reviews', 'status' => 201, 'response' => 'review.schema.json'],
        'inputs.submit-drafts.json' => ['schema' => 'inputs.schema.json#/$defs/SubmitDraftsInput', 'method' => 'POST', 'path' => '/api/v1/reviews/{reviewId}/drafts/submit', 'status' => 201, 'response' => 'batch-submission.schema.json', 'draft' => true],
        'inputs.suggest-request.json' => ['schema' => 'inputs.schema.json#/$defs/SuggestRequestInput', 'method' => 'POST', 'path' => '/api/v1/reviews/{reviewId}/suggestions', 'status' => 201, 'response' => self::RESULT, 'bearer' => true],
        'inputs.unlink-pull-request.json' => ['schema' => 'inputs.schema.json#/$defs/UnlinkPullRequestInput', 'method' => 'POST', 'path' => '/api/v1/requests/{requestId}/unlink-pull-request', 'status' => 200, 'response' => self::RESULT, 'bearer' => true, 'claimed' => [self::FIXTURE_REQUEST_ID], 'linked' => true],
        'inputs.update-draft.json' => ['schema' => 'inputs.schema.json#/$defs/UpdateDraftInput', 'method' => 'PATCH', 'path' => '/api/v1/drafts/{draftId}', 'status' => 200, 'response' => 'draft.schema.json', 'draft' => true],
        'inputs.viewed-file.json' => ['schema' => 'inputs.schema.json#/$defs/ViewedFileInput', 'method' => 'PUT', 'path' => '/api/v1/reviews/{reviewId}/viewed-files', 'status' => 200, 'response' => 'lists.schema.json#/$defs/ViewedFiles'],
        'inputs.wont-do.json' => ['schema' => 'inputs.schema.json#/$defs/WontDoInput', 'method' => 'POST', 'path' => '/api/v1/requests/{requestId}/wont-do', 'status' => 200, 'response' => self::RESULT],
    ];

    /**
     * @param array{schema: string, method: string, path: string, status: int, response: string, bearer?: true, claimed?: list<string>, draft?: true, proposed?: true, linked?: true, suggested?: true} $case
     */
    #[TestDox('Every request body fixture of docs/specs is accepted by its endpoint')]
    #[DataProvider('fixtures')]
    public function testFixtureIsAccepted(string $fixture, array $case): void
    {
        $fixtureFiles = array_map(basename(...), glob(self::FIXTURE_DIRECTORY.'/inputs.*.json') ?: []);
        self::assertSame(array_keys(self::CASES), $fixtureFiles, 'Every inputs fixture has a case.');
        $json = (string) file_get_contents(self::FIXTURE_DIRECTORY."/{$fixture}");
        self::assertSame([], SchemaValidator::errors($json, $case['schema']));
        self::mockTime('2026-10-01 09:00:00');
        $this->useTemporaryLocalSources();
        $this->newSource('billing');
        $this->newSource('ledger')->commit([self::INVOICE_TOTALS => self::invoiceTotals()]);
        $client = static::createClient();
        $user = self::signIn($client);
        self::storeCandidate($user->getTenantId());
        $repositoryId = self::connectAndClone($client, 'ledger');
        $reviewId = (string) self::startReviewThroughApi($client, $repositoryId)['id'];
        [, $secret] = self::storeToken($user);
        $firstRequest = isset($case['suggested'])
            ? self::agentRequest($client, $secret, 'POST', "/api/v1/reviews/{$reviewId}/suggestions", ['body' => 'Round half cents up.'], 201)['request']
            : self::openRequest($client, $reviewId);
        $ids = [
            self::FIXTURE_REPOSITORY_ID => $repositoryId,
            self::FIXTURE_REVIEW_ID => $reviewId,
            self::FIXTURE_REQUEST_ID => (string) $firstRequest['id'],
            self::FIXTURE_SECOND_REQUEST_ID => (string) self::openRequest($client, $reviewId)['id'],
        ];

        foreach ($case['claimed'] ?? [] as $fixtureId) {
            self::agentRequest($client, $secret, 'POST', "/api/v1/requests/{$ids[$fixtureId]}/claim");
        }

        if (isset($case['proposed'])) {
            $proposed = self::agentRequest($client, $secret, 'POST', "/api/v1/requests/{$ids[self::FIXTURE_REQUEST_ID]}/propose", ['plan' => 'Extract `TaxRounding`.']);
            $ids[self::FIXTURE_PROPOSAL_EVENT_ID] = (string) $proposed['request']['proposal']['eventId'];
        }

        $body = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        array_walk_recursive($body, static function (mixed &$value) use ($ids): void {
            $value = \is_string($value) ? ($ids[$value] ?? $value) : $value;
        });

        if (isset($case['linked'])) {
            self::agentRequest($client, $secret, 'POST', '/api/v1/pull-request-links', ['url' => $body['url'], 'requestIds' => [$ids[self::FIXTURE_REQUEST_ID]]], schema: 'link-pull-request-result.schema.json');
        }
        $uri = strtr($case['path'], [
            '{reviewId}' => $reviewId,
            '{repositoryId}' => $repositoryId,
            '{requestId}' => $ids[self::FIXTURE_REQUEST_ID],
            '{draftId}' => isset($case['draft']) ? (string) self::createDraft($client, $reviewId)['id'] : '',
            '{targetReviewId}' => str_contains($case['path'], '{targetReviewId}') ? (string) self::startReviewThroughApi($client, $repositoryId)['id'] : '',
        ]);

        $response = isset($case['bearer']) ? self::bearerRequest($client, $secret, $case['method'], $uri, $body) : self::apiRequest($client, $case['method'], $uri, $body);

        self::assertApiResponse($response, $case['status'], $case['response']);
    }

    /**
     * @return iterable<string, array{string, array{schema: string, method: string, path: string, status: int, response: string, bearer?: true, claimed?: list<string>, draft?: true, proposed?: true, linked?: true, suggested?: true}}>
     */
    public static function fixtures(): iterable
    {
        foreach (self::CASES as $fixture => $case) {
            yield $fixture => [$fixture, $case];
        }
    }

    /**
     * The PHP fixture class, with comment lines up to line 60, so the range 52 to 58 exists.
     */
    private static function invoiceTotals(): string
    {
        $lines = explode("\n", rtrim((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/php/InvoiceTotals.php')));

        for ($line = \count($lines) + 1; $line <= 60; ++$line) {
            $lines[] = "// Line {$line}";
        }

        return implode("\n", $lines)."\n";
    }
}
