<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/specs/README.md section "Rate limits". The mock clock drives the limiters through
 * tests/Support/Clock/rate-limiter-time.php.
 */
final class RateLimitTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    #[TestDox('The call after the limit gets 429 with Retry-After, and a call after the interval succeeds')]
    public function testCallAfterTheLimitWaitsForTheInterval(): void
    {
        $this->setEnvironmentVariable('APP_RATE_LIMIT_API_TOKEN_PER_MINUTE', '2');
        self::mockTime('2026-10-13 09:00:00');
        $client = static::createClient();
        [, $secret] = self::storeToken(TenantFixtures::of(self::getContainer())->create());
        self::bearerRequest($client, $secret, 'GET', '/api/v1/me');
        self::bearerRequest($client, $secret, 'GET', '/api/v1/me');

        $refused = self::bearerRequest($client, $secret, 'GET', '/api/v1/me');

        self::assertRateLimited($refused, retryAfterSeconds: 60, limit: 2);
        Clock::get()->sleep(60);
        self::assertApiResponse(self::bearerRequest($client, $secret, 'GET', '/api/v1/me'), 200, 'me.schema.json');
    }

    #[TestDox('Two tokens of one user have separate api_token buckets and share the api_user bucket')]
    public function testTokensShareTheUserWindow(): void
    {
        $this->setEnvironmentVariable('APP_RATE_LIMIT_API_TOKEN_PER_MINUTE', '2');
        $this->setEnvironmentVariable('APP_RATE_LIMIT_API_USER_PER_MINUTE', '3');
        self::mockTime('2026-10-13 09:00:00');
        $client = static::createClient();
        $user = TenantFixtures::of(self::getContainer())->create();
        [, $first] = self::storeToken($user, name: 'claude-code laptop');
        [, $second] = self::storeToken($user, name: 'codex desktop');
        self::bearerRequest($client, $first, 'GET', '/api/v1/me');
        self::bearerRequest($client, $first, 'GET', '/api/v1/me');

        $firstRefused = self::bearerRequest($client, $first, 'GET', '/api/v1/me');
        $secondAccepted = self::bearerRequest($client, $second, 'GET', '/api/v1/me');
        $secondRefused = self::bearerRequest($client, $second, 'GET', '/api/v1/me');

        self::assertRateLimited($firstRefused, retryAfterSeconds: 60, limit: 2);
        self::assertApiResponse($secondAccepted, 200, 'me.schema.json');
        self::assertRateLimited($secondRefused, retryAfterSeconds: null, limit: 3);
    }

    #[TestDox('A review start past the limit of review_start or sync_fetch gets 429')]
    #[DataProvider('reviewStartLimiters')]
    public function testReviewStartPastTheLimit(string $variable, int $retryAfterSeconds): void
    {
        $this->setEnvironmentVariable($variable, '1');
        self::mockTime('2026-10-13 09:00:00');
        $this->useTemporaryLocalSources();
        $this->newSource();
        $client = static::createClient();
        self::signIn($client);
        $repositoryId = self::connectAndClone($client);
        self::startReviewThroughApi($client, $repositoryId);

        $refused = self::apiRequest($client, 'POST', "/api/v1/repositories/{$repositoryId}/reviews", ['ref' => 'main']);

        self::assertRateLimited($refused, $retryAfterSeconds, limit: 1);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function reviewStartLimiters(): iterable
    {
        yield 'review_start' => ['APP_RATE_LIMIT_REVIEW_START_PER_HOUR', 3600];
        yield 'sync_fetch' => ['APP_RATE_LIMIT_SYNC_FETCH_PER_MINUTE', 60];
    }

    /**
     * @param int|null $retryAfterSeconds Null to accept the value of the limiter, which the header repeats
     */
    private static function assertRateLimited(Response $response, ?int $retryAfterSeconds, int $limit): void
    {
        $error = self::assertApiResponse($response, 429, 'error.schema.json')['error'];
        self::assertIsArray($error);
        self::assertIsArray($error['details']);
        $retryAfterSeconds ??= $error['details']['retryAfterSeconds'];
        self::assertSame(['rate_limited', ['retryAfterSeconds' => $retryAfterSeconds, 'limit' => $limit]], [$error['code'], $error['details']]);
        self::assertSame(
            [(string) $retryAfterSeconds, (string) $limit, '0'],
            [$response->headers->get('Retry-After'), $response->headers->get('X-RateLimit-Limit'), $response->headers->get('X-RateLimit-Remaining')],
        );
    }
}
