<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Domain\Identity\RequestId;
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
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Q20 to Q22 and the `suggestion` filter of Q1. The agent calls REST with a token of the signed-in user.
 */
final class SuggestionTest extends WebTestCase
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

    private const string TOTALS_CLASS = 'src/Billing/Totals.php';

    private const string TOTAL_FQN = 'App\Billing\InvoiceTotals::total()';

    private const string BODY = 'Round half cents up: use `PHP_ROUND_HALF_UP`.';

    private KernelBrowser $client;

    private string $secret;

    private string $reviewId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit([...self::codeFiles(), self::TOTALS_CLASS => (string) file_get_contents(\dirname(__DIR__, 2).'/Fixtures/php/InvoiceTotals.php')]);
        $this->client = static::createClient();
        $user = self::signIn($this->client);
        $this->reviewId = self::startCodeReview($this->client);
        [, $this->secret] = self::storeToken($user);
    }

    #[TestDox('A token suggests line, range, symbol, file, and general requests that wait for the user')]
    public function testTokenSuggestsEveryAnchorKind(): void
    {
        $anchors = [
            'line' => ['kind' => 'line', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 4, 'endLine' => 4]],
            'range' => ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 4, 'endLine' => 6]],
            'symbol' => ['kind' => 'symbol', 'path' => self::TOTALS_CLASS, 'symbol' => ['fqn' => self::TOTAL_FQN]],
            'file' => ['kind' => 'file', 'path' => 'README.md'],
            'general' => null,
        ];

        foreach ($anchors as $name => $anchor) {
            $response = $this->suggestResponse($anchor);
            $result = self::assertApiResponse($response, 201, 'request-command-result.schema.json');
            $request = $result['request'];

            self::assertSame("/api/v1/requests/{$request['id']}", $response->headers->get('Location'), $name);
            self::assertSame(
                [$anchor['kind'] ?? null, 'open', 'pending', 'user', 'agent', false, ['reply']],
                [$request['anchor']['kind'] ?? null, $request['status'], $request['suggestion'], $request['awaiting'], $request['author']['kind'], $request['requireApproval'], $result['allowedActions']],
                $name,
            );
            self::assertSame([['request.opened', 'agent', self::BODY]], array_map(static fn (array $event): array => [$event['type'], $event['actor']['kind'], $event['body']], $result['events']), $name);
        }
    }

    #[TestDox('A session gets 403 forbidden')]
    public function testSessionGetsForbidden(): void
    {
        $response = self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/suggestions", ['body' => self::BODY]);

        self::assertSame(CurrentActor::BEARER_ONLY, self::assertApiError($response, 403, 'forbidden')['message']);
    }

    #[TestDox('A claim of a pending suggestion gets 409 invalid_transition')]
    public function testClaimOfPendingSuggestionGetsInvalidTransition(): void
    {
        $requestId = $this->suggest();

        self::assertApiError(self::bearerRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$requestId}/claim"), 409, 'invalid_transition');
    }

    #[TestDox('Accept with requireApproval hands the request to the agent, who claims it')]
    public function testAcceptHandsTheRequestToTheAgent(): void
    {
        $requestId = $this->suggest();

        $accepted = self::requestCommand($this->client, $requestId, 'accept-suggestion', ['requireApproval' => true]);

        self::assertSame(['accepted', true, 'agent'], [$accepted['request']['suggestion'], $accepted['request']['requireApproval'], $accepted['request']['awaiting']]);
        self::assertSame([['request.suggestion_accepted', null]], array_map(static fn (array $event): array => [$event['type'], $event['body']], $accepted['events']));
        self::assertSame('in_progress', self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$requestId}/claim")['request']['status']);
    }

    #[TestDox('Dismiss closes the request as wont_do, and a reopen makes it claimable')]
    public function testDismissClosesAndReopenMakesClaimable(): void
    {
        $requestId = $this->suggest();

        $dismissed = self::requestCommand($this->client, $requestId, 'dismiss-suggestion', ['note' => 'Our tax office requires half-even rounding.']);
        $reopened = self::requestCommand($this->client, $requestId, 'reopen', ['note' => 'The tax office changed the rule.']);

        self::assertSame(['dismissed', 'wont_do', 'wont_do', true, 'none'], [$dismissed['request']['suggestion'], $dismissed['request']['status'], $dismissed['request']['resolution'], $dismissed['request']['closed'], $dismissed['request']['awaiting']]);
        self::assertSame([['request.suggestion_dismissed', 'Our tax office requires half-even rounding.']], array_map(static fn (array $event): array => [$event['type'], $event['body']], $dismissed['events']));
        self::assertSame(['accepted', 'reopened'], [$reopened['request']['suggestion'], $reopened['request']['status']]);
        self::assertSame('in_progress', self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$requestId}/claim")['request']['status']);
    }

    /**
     * Copies of the first suggestion's row stand for 498 further pending suggestions.
     */
    #[TestDox('The 500th pending suggestion is accepted, the 501st gets 422 invalid_input')]
    public function testThe501stPendingSuggestionGetsInvalidInput(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO change_request SELECT (jsonb_populate_record(source, jsonb_build_object('id', gen_random_uuid()))).* FROM change_request source, generate_series(1, 498) WHERE source.id = ?",
            [RequestId::fromString($this->suggest())->toRfc4122()],
        );

        self::assertApiResponse($this->suggestResponse(null), 201, 'request-command-result.schema.json');
        self::assertApiError($this->suggestResponse(null), 422, 'invalid_input');
    }

    #[TestDox('A closed review gets 409 review_closed')]
    public function testClosedReviewGetsReviewClosed(): void
    {
        self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/close"), 200, 'review.schema.json');

        self::assertApiError($this->suggestResponse(null), 409, 'review_closed');
    }

    #[TestDox('The suggestion filter of Q1 lists the suggestions in that state, never a request of the user')]
    public function testSuggestionFilterListsSuggestionsInThatState(): void
    {
        self::openRequest($this->client, $this->reviewId);
        $pending = $this->suggest();
        $accepted = $this->suggest();
        self::requestCommand($this->client, $accepted, 'accept-suggestion');

        $listed = array_map(
            fn (string $state): array => array_column(self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/requests?suggestion={$state}"), 200, 'lists.schema.json#/$defs/RequestPage')['items'], 'id'),
            ['pending', 'accepted', 'dismissed'],
        );

        self::assertSame([[$pending], [$accepted], []], $listed);
    }

    /**
     * @return string The ID of the general suggestion
     */
    private function suggest(): string
    {
        return (string) self::assertApiResponse($this->suggestResponse(null), 201, 'request-command-result.schema.json')['request']['id'];
    }

    /**
     * @param array<string, mixed>|null $anchor
     */
    private function suggestResponse(?array $anchor): Response
    {
        return self::bearerRequest($this->client, $this->secret, 'POST', "/api/v1/reviews/{$this->reviewId}/suggestions", ['anchor' => $anchor, 'body' => self::BODY]);
    }
}
