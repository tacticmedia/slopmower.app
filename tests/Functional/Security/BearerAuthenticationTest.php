<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Domain\ApiToken\ApiTokenSecret;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LoggerDataCollector;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class BearerAuthenticationTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use JsonApiAssertions;
    use ResetDatabase;

    private const string CHALLENGE = 'Bearer realm="holistic-review"';
    private const string INVALID_TOKEN_CHALLENGE = 'Bearer realm="holistic-review", error="invalid_token"';

    #[TestDox('A valid token makes the caller an agent with the token name')]
    public function testValidTokenMakesAnAgent(): void
    {
        $client = static::createClient();
        $user = TenantFixtures::of(self::getContainer())->create();
        [$token, $secret] = self::storeToken($user, name: 'claude-code laptop');

        $me = self::assertApiResponse(self::bearerRequest($client, $secret, 'GET', '/api/v1/me'), 200, 'me.schema.json');

        $userId = $user->getId()->toString();
        self::assertSame(['kind' => 'agent', 'userId' => $userId, 'agentName' => 'claude-code laptop', 'name' => 'claude-code laptop', 'avatarUrl' => null], $me['caller']);
        self::assertIsArray($me['user']);
        self::assertSame($userId, $me['user']['id']);
        self::assertIsArray($me['token']);
        self::assertSame($token->getId()->toString(), $me['token']['id']);
    }

    #[TestDox('Malformed, unknown, revoked, and expired tokens get 401 with error invalid_token')]
    #[DataProvider('rejectedTokens')]
    public function testRejectedTokens(string $case, bool $readsTheDatabase): void
    {
        self::mockTime('2026-10-02 08:00:00');
        $client = static::createClient();
        $user = TenantFixtures::of(self::getContainer())->create();
        $secret = match ($case) {
            'malformed' => 'hr_'.str_repeat('a', 42),
            'unknown' => ApiTokenSecret::generate()->value,
            'revoked' => self::storeToken($user, revoked: true)[1],
            'expired' => self::storeToken($user, expiresAt: Clock::get()->now())[1],
            default => self::fail("Unknown case {$case}."),
        };
        $client->enableProfiler();

        self::assertInvalidToken(self::bearerRequest($client, $secret, 'GET', '/api/v1/me'));
        $collector = self::profile($client)->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        $tokenLookups = array_filter(array_merge(...array_values($collector->getQueries())), static fn (array $query): bool => str_contains((string) $query['sql'], 'api_token'));
        self::assertSame($readsTheDatabase, [] !== $tokenLookups, 'Only a well-formed token is looked up.');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function rejectedTokens(): iterable
    {
        yield 'malformed' => ['malformed', false];
        yield 'unknown' => ['unknown', true];
        yield 'revoked' => ['revoked', true];
        yield 'expired at the expiry time' => ['expired', true];
    }

    #[TestDox('A Bearer header that the extractor rejects gets the same 401 with the Error body')]
    public function testHeaderThatTheExtractorRejects(): void
    {
        $client = static::createClient();

        self::assertInvalidToken(self::bearerRequest($client, 'hr_%%%', 'GET', '/api/v1/me'));
    }

    #[TestDox('A 401 has the Error body and exactly the WWW-Authenticate header of docs/specs')]
    public function testUnauthorizedHeaders(): void
    {
        $client = static::createClient();

        $withoutToken = self::apiRequest($client, 'GET', '/api/v1/me');
        $withToken = self::bearerRequest($client, ApiTokenSecret::generate()->value, 'GET', '/api/v1/me');

        self::assertApiError($withoutToken, 401, 'unauthorized');
        self::assertSame([self::CHALLENGE], $withoutToken->headers->all('WWW-Authenticate'));
        self::assertApiError($withToken, 401, 'unauthorized');
        self::assertSame([self::INVALID_TOKEN_CHALLENGE], $withToken->headers->all('WWW-Authenticate'));
    }

    #[TestDox('A bearer response has no Set-Cookie header')]
    public function testNoSetCookie(): void
    {
        $client = static::createClient();
        [, $secret] = self::storeToken(TenantFixtures::of(self::getContainer())->create());

        $success = self::bearerRequest($client, $secret, 'GET', '/api/v1/me');
        $failure = self::bearerRequest($client, ApiTokenSecret::generate()->value, 'GET', '/api/v1/me');

        self::assertSame(200, $success->getStatusCode());
        self::assertSame([], $success->headers->getCookies());
        self::assertSame(401, $failure->getStatusCode());
        self::assertSame([], $failure->headers->getCookies());
    }

    #[TestDox('The bearer token wins over a session cookie')]
    public function testBearerWinsOverSession(): void
    {
        $client = static::createClient();
        self::signIn($client, 'jan');
        $tokenOwner = TenantFixtures::of(self::getContainer())->withLogin('agent-owner')->create();
        [, $secret] = self::storeToken($tokenOwner, name: 'codex desktop');

        $me = self::assertApiResponse(self::bearerRequest($client, $secret, 'GET', '/api/v1/me'), 200, 'me.schema.json');

        self::assertIsArray($me['caller']);
        self::assertSame(['agent', $tokenOwner->getId()->toString(), 'codex desktop'], [$me['caller']['kind'], $me['caller']['userId'], $me['caller']['agentName']]);
    }

    #[TestDox('A bearer call with a session cookie leaves the session unchanged')]
    public function testSessionUnchanged(): void
    {
        $client = static::createClient();
        $sessionUser = self::signIn($client, 'jan');
        [, $secret] = self::storeToken($sessionUser);
        $before = self::sessionFileContent($client);

        $response = self::bearerRequest($client, $secret, 'GET', '/api/v1/me');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $response->headers->getCookies());
        self::assertSame($before, self::sessionFileContent($client));
        $me = self::assertApiResponse(self::apiRequest($client, 'GET', '/api/v1/me'), 200, 'me.schema.json');
        self::assertIsArray($me['caller']);
        self::assertSame('user', $me['caller']['kind']);
    }

    #[TestDox('The secret never appears in var/log')]
    public function testSecretNotLogged(): void
    {
        $client = static::createClient();
        $user = TenantFixtures::of(self::getContainer())->create();
        [, $valid] = self::storeToken($user);
        [, $revoked] = self::storeToken($user, revoked: true);

        foreach ([$valid, $revoked] as $secret) {
            $client->enableProfiler();
            self::bearerRequest($client, $secret, 'GET', '/api/v1/me');
            $logs = self::profile($client)->getCollector('logger');
            self::assertInstanceOf(LoggerDataCollector::class, $logs);

            self::assertNotSame([], $logs->getProcessedLogs(), 'The request logs something.');
            self::assertStringNotContainsString($secret, serialize($logs->getProcessedLogs()));
        }

        foreach (Finder::create()->files()->in(self::getContainer()->getParameter('kernel.logs_dir')) as $file) {
            self::assertStringNotContainsString($valid, $file->getContents(), $file->getFilename());
            self::assertStringNotContainsString($revoked, $file->getContents(), $file->getFilename());
        }
    }

    private static function assertInvalidToken(Response $response): void
    {
        self::assertSame(
            ['code' => 'unauthorized', 'message' => 'The API token is malformed, unknown, revoked, or expired.', 'details' => null],
            self::assertApiError($response, 401, 'unauthorized'),
        );
        self::assertSame([self::INVALID_TOKEN_CHALLENGE], $response->headers->all('WWW-Authenticate'));
    }

    private static function profile(KernelBrowser $client): Profile
    {
        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);

        return $profile;
    }

    private static function sessionFileContent(KernelBrowser $client): string
    {
        $sessionId = $client->getCookieJar()->get('MOCKSESSID')?->getValue();
        self::assertNotNull($sessionId);

        return (string) file_get_contents(self::getContainer()->getParameter('kernel.cache_dir')."/sessions/{$sessionId}.mocksess");
    }
}
