<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\SchemaValidator;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Mcp\McpTestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/specs/mcp.md section 1: a wrong token gets HTTP 401 before JSON-RPC.
 */
final class McpAuthTest extends WebTestCase
{
    use ApiFixtures;
    use ResetDatabase;

    private const string CHALLENGE = 'Bearer realm="holistic-review"';
    private const string INVALID_TOKEN_CHALLENGE = 'Bearer realm="holistic-review", error="invalid_token"';

    #[TestDox('A call without a token gets 401 with WWW-Authenticate')]
    public function testCallWithoutTokenGets401(): void
    {
        $response = new McpTestClient(static::createClient(), null)->initializeResponse();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame([self::CHALLENGE], $response->getHeader('WWW-Authenticate', false));
    }

    #[TestDox('A revoked token gets 401')]
    public function testRevokedTokenGets401(): void
    {
        $client = static::createClient();
        [, $secret] = self::storeToken(TenantFixtures::of(self::getContainer())->create(), revoked: true);

        $response = new McpTestClient($client, $secret)->initializeResponse();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame([self::INVALID_TOKEN_CHALLENGE], $response->getHeader('WWW-Authenticate', false));
    }

    #[TestDox('A session cookie alone gets 401')]
    public function testSessionCookieAloneGets401(): void
    {
        $client = static::createClient();
        self::signIn($client);
        self::assertSame(200, self::apiRequest($client, 'GET', '/api/v1/me')->getStatusCode(), 'The session is valid.');

        $response = new McpTestClient($client, null)->initializeResponse();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    #[TestDox('Every 401 has the Error body')]
    #[DataProvider('unauthorizedCalls')]
    public function testEvery401HasTheErrorBody(string $case): void
    {
        $client = static::createClient();
        $secret = match ($case) {
            'no token' => null,
            'revoked token' => self::storeToken(TenantFixtures::of(self::getContainer())->create(), revoked: true)[1],
            default => self::fail("Unknown case {$case}."),
        };

        $response = new McpTestClient($client, $secret)->initializeResponse();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame([], SchemaValidator::errors($response->getContent(), 'error.schema.json'), $response->getContent());
        self::assertSame('unauthorized', json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR)['error']['code']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unauthorizedCalls(): iterable
    {
        foreach (['no token', 'revoked token'] as $case) {
            yield $case => [$case];
        }
    }

    #[TestDox('/.well-known/oauth-protected-resource answers 404')]
    public function testProtectedResourceMetadataIsNotFound(): void
    {
        $client = static::createClient();

        $client->request('GET', '/.well-known/oauth-protected-resource');

        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }
}
