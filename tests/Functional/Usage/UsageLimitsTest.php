<?php

declare(strict_types=1);

namespace App\Tests\Functional\Usage;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\DraftApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/specs/README.md section "Usage limits". No MCP tool writes what a usage limit counts.
 */
final class UsageLimitsTest extends WebTestCase
{
    use ApiFixtures;
    use DraftApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource('billing');
        $this->newSource('shipping');
    }

    #[TestDox('A connect past the limit of repositories gives limit_exceeded')]
    public function testConnectPastTheLimit(): void
    {
        $this->signInWithLimit('APP_PLAN_FREE_REPOSITORIES', '1');
        self::connectAndClone($this->client, 'billing');

        $response = self::apiRequest($this->client, 'POST', '/api/v1/repositories', ['source' => 'local', 'directory' => 'shipping']);

        self::assertLimitExceeded($response, ['limit' => 'repositories', 'max' => 1, 'current' => 1]);
    }

    #[TestDox('A review start past the limit of open reviews gives limit_exceeded')]
    public function testStartPastTheLimit(): void
    {
        $this->signInWithLimit('APP_PLAN_FREE_OPEN_REVIEWS', '1');
        $repositoryId = self::connectAndClone($this->client);
        self::startReviewThroughApi($this->client, $repositoryId);

        $response = self::apiRequest($this->client, 'POST', "/api/v1/repositories/{$repositoryId}/reviews", ['ref' => 'main']);

        self::assertLimitExceeded($response, ['limit' => 'open_reviews', 'max' => 1, 'current' => 1]);
    }

    #[TestDox('An open request past the limit of the review gives limit_exceeded')]
    public function testOpenPastTheLimit(): void
    {
        $this->signInWithLimit('APP_PLAN_FREE_REQUESTS_PER_REVIEW', '1');
        $reviewId = self::startCodeReview($this->client);
        self::openRequest($this->client, $reviewId);

        $response = self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$reviewId}/requests", ['anchor' => null, 'body' => 'Add a focus mode.']);

        self::assertLimitExceeded($response, ['limit' => 'requests_per_review', 'max' => 1, 'current' => 1]);
    }

    #[TestDox('A submission whose new requests pass the limit of the review gives limit_exceeded and keeps the drafts')]
    public function testSubmitPastTheLimit(): void
    {
        $this->signInWithLimit('APP_PLAN_FREE_REQUESTS_PER_REVIEW', '2');
        $reviewId = self::startCodeReview($this->client);
        $requestId = (string) self::openRequest($this->client, $reviewId)['id'];
        self::createReplyDraft($this->client, $reviewId, $requestId);
        self::createDraft($this->client, $reviewId);
        self::createDraft($this->client, $reviewId, null);

        $response = self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$reviewId}/drafts/submit", []);

        self::assertLimitExceeded($response, ['limit' => 'requests_per_review', 'max' => 2, 'current' => 1]);
        self::assertCount(3, self::listDrafts($this->client, $reviewId));
    }

    #[TestDox('A carry-over whose requests pass the limit of the target review gives limit_exceeded')]
    public function testCarryOverPastTheLimit(): void
    {
        $this->signInWithLimit('APP_PLAN_FREE_REQUESTS_PER_REVIEW', '2');
        $repositoryId = self::connectAndClone($this->client);
        $sourceId = (string) self::startReviewThroughApi($this->client, $repositoryId)['id'];
        $targetId = (string) self::startReviewThroughApi($this->client, $repositoryId)['id'];
        self::openRequest($this->client, $sourceId, null);
        self::openRequest($this->client, $sourceId, null);
        self::openRequest($this->client, $targetId, null);

        $response = self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$targetId}/carry-over", ['fromReviewId' => $sourceId]);

        self::assertLimitExceeded($response, ['limit' => 'requests_per_review', 'max' => 2, 'current' => 1]);
    }

    #[TestDox('A limit of zero or an empty value is off')]
    #[DataProvider('limitsThatAreOff')]
    public function testLimitIsOff(string $value): void
    {
        $this->signInWithLimit('APP_PLAN_FREE_REPOSITORIES', $value);
        self::connectAndClone($this->client, 'billing');

        self::connectAndClone($this->client, 'shipping');

        $usage = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/me'), 200, 'me.schema.json')['usage'];
        self::assertIsArray($usage);
        self::assertSame(['limit' => 'repositories', 'max' => null, 'current' => 2], $usage[0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function limitsThatAreOff(): iterable
    {
        yield 'zero' => ['0'];
        yield 'empty' => [''];
    }

    private function signInWithLimit(string $variable, string $value): void
    {
        $this->setEnvironmentVariable($variable, $value);
        $this->client = static::createClient();
        self::signIn($this->client);
    }

    /**
     * @param array{limit: string, max: int, current: int} $details
     */
    private static function assertLimitExceeded(Response $response, array $details): void
    {
        $error = self::assertApiResponse($response, 409, 'error.schema.json')['error'];
        self::assertIsArray($error);
        self::assertSame(['limit_exceeded', $details], [$error['code'], $error['details']]);
    }
}
