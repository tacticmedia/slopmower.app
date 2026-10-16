<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\RequestCommands;
use App\Domain\Actor\Actor;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Entity\User;
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
 * Q23 of docs/specs/rest-api-requests.md.
 */
final class SetTitleTest extends WebTestCase
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

    private User $user;

    private string $reviewId;

    private string $requestId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->reviewId = self::startCodeReview($this->client);
        $this->requestId = (string) self::openRequest($this->client, $this->reviewId)['id'];
    }

    #[TestDox('The user retitles a verified request, which keeps its status and awaiting')]
    public function testUserRetitlesAVerifiedRequest(): void
    {
        $requestId = RequestId::fromString($this->requestId);
        self::asAgent($this->user, static function (RequestCommands $commands, Actor $agent) use ($requestId): void {
            $commands->claim(new Claim($requestId), $agent);
            $commands->markReadyForReview(new MarkReadyForReview($requestId, 'Totals use Money.'), $agent);
        });
        self::requestCommand($this->client, $this->requestId, 'verify');

        $result = self::requestCommand($this->client, $this->requestId, 'title', ['title' => 'Money totals']);

        self::assertSame(['Money totals', 'Money totals', 'verified', 'none'], [$result['request']['title'], $result['request']['summary'], $result['request']['status'], $result['request']['awaiting']]);
        self::assertSame([['request.retitled', 'user', 'Money totals', 'verified']], array_map(static fn (array $event): array => [$event['type'], $event['actor']['kind'], $event['body'], $event['statusAfter']], $result['events']));
    }

    #[TestDox('An agent sets the title of an untitled request, and its second call gets 409 invalid_transition')]
    public function testAgentSetsTheTitleOnce(): void
    {
        [, $secret] = self::storeToken($this->user);
        $uri = "/api/v1/requests/{$this->requestId}/title";

        $result = self::agentRequest($this->client, $secret, 'POST', $uri, ['title' => 'Money totals']);
        $error = self::assertApiError(self::bearerRequest($this->client, $secret, 'POST', $uri, ['title' => 'Money totals']), 409, 'invalid_transition');

        self::assertSame(['Money totals', ['request.retitled']], [$result['request']['title'], array_column($result['events'], 'type')]);
        self::assertNotContains('set_title', $result['allowedActions']);
        self::assertSame('set_title', $error['details']['action'] ?? null);
    }

    #[TestDox('A closed review gives 409 review_closed')]
    public function testClosedReviewGetsReviewClosed(): void
    {
        self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/close"), 200, 'review.schema.json');

        self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/requests/{$this->requestId}/title", ['title' => 'Money totals']), 409, 'review_closed');
    }
}
