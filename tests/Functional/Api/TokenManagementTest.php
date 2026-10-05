<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Ulid;

final class TokenManagementTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use JsonApiAssertions;
    use ResetDatabase;

    private const string WITH_SECRET = 'api-token.schema.json#/$defs/ApiTokenWithSecret';
    private const string TOKEN_LIST = 'lists.schema.json#/$defs/TokenList';

    #[TestDox('The create response shows the secret once')]
    public function testCreateShowsTheSecretOnce(): void
    {
        self::mockTime('2026-10-02 08:00:00');
        $client = static::createClient();
        $user = self::signIn($client);
        $alpha = self::storeRepository($user->getTenantId(), 'alpha');

        $created = self::assertApiResponse(
            self::apiRequest($client, 'POST', '/api/v1/tokens', ['name' => 'claude-code laptop', 'repositoryId' => strtolower($alpha->getId()->toString()), 'expiresAt' => '2026-12-31T10:00:00+10:00']),
            201,
            self::WITH_SECRET,
        );

        self::assertIsString($created['secret']);
        self::assertSame(substr($created['secret'], 0, 9), $created['prefix']);
        unset($created['id'], $created['secret'], $created['prefix']);
        self::assertSame([
            'name' => 'claude-code laptop',
            'scope' => 'repository',
            'repositoryId' => $alpha->getId()->toString(),
            'repositoryName' => 'alpha',
            'state' => 'active',
            'createdAt' => '2026-10-02T08:00:00Z',
            'expiresAt' => '2026-12-31T00:00:00Z',
            'lastUsedAt' => null,
            'revokedAt' => null,
        ], $created);
        $listed = self::assertApiResponse(self::apiRequest($client, 'GET', '/api/v1/tokens'), 200, self::TOKEN_LIST);
        self::assertIsArray($listed['items']);
        self::assertIsArray($listed['items'][0]);
        self::assertArrayNotHasKey('secret', $listed['items'][0]);
    }

    #[TestDox('The list shows neither the secret nor the hash')]
    public function testListHidesSecretAndHash(): void
    {
        $client = static::createClient();
        self::signIn($client);
        $created = self::assertApiResponse(self::apiRequest($client, 'POST', '/api/v1/tokens', ['name' => 'codex desktop']), 201, self::WITH_SECRET);
        self::assertIsString($created['secret']);

        $listed = self::assertApiResponse(self::apiRequest($client, 'GET', '/api/v1/tokens'), 200, self::TOKEN_LIST);

        self::assertIsArray($listed['items']);
        self::assertCount(1, $listed['items']);
        $content = json_encode($listed, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($created['secret'], $content);
        self::assertStringNotContainsString(hash('sha256', $created['secret']), $content);
    }

    #[TestDox('The list holds active, expired, and revoked tokens, newest first')]
    public function testListHoldsAllStatesNewestFirst(): void
    {
        self::mockTime('2026-10-02 08:00:00');
        $client = static::createClient();
        $user = self::signIn($client);
        self::storeToken($user, expiresAt: Clock::get()->now()->modify('+1 hour'), name: 'expired');
        self::mockTime('2026-10-02 09:00:00');
        self::storeToken($user, revoked: true, name: 'revoked');
        self::mockTime('2026-10-02 10:00:00');
        self::storeToken($user, name: 'active');

        $listed = self::assertApiResponse(self::apiRequest($client, 'GET', '/api/v1/tokens'), 200, self::TOKEN_LIST);

        self::assertIsArray($listed['items']);
        self::assertSame(['active' => 'active', 'revoked' => 'revoked', 'expired' => 'expired'], array_column($listed['items'], 'state', 'name'));
    }

    #[TestDox('Revoke returns 204, also a second time')]
    public function testRevokeTwice(): void
    {
        self::mockTime('2026-10-02 08:00:00');
        $client = static::createClient();
        [$token, $secret] = self::storeToken(self::signIn($client));
        $path = "/api/v1/tokens/{$token->getId()}";

        $first = self::apiRequest($client, 'DELETE', $path);
        self::mockTime('2026-10-02 09:00:00');
        $second = self::apiRequest($client, 'DELETE', $path);

        self::assertSame([204, '', 204, ''], [$first->getStatusCode(), $first->getContent(), $second->getStatusCode(), $second->getContent()]);
        self::assertSame('2026-10-02 08:00:00', self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT revoked_at FROM api_token WHERE id = ?',
            [$token->getId()->toRfc4122()],
        ));
        self::assertApiError(self::bearerRequest($client, $secret, 'GET', '/api/v1/me'), 401, 'unauthorized');
    }

    #[TestDox('An expiry outside one minute to 366 days gives 422')]
    #[DataProvider('expiries')]
    public function testExpiryWindow(string $expiresAt, int $status): void
    {
        self::mockTime('2026-10-02 08:00:00');
        $client = static::createClient();
        self::signIn($client);

        $response = self::apiRequest($client, 'POST', '/api/v1/tokens', ['name' => 'codex desktop', 'expiresAt' => $expiresAt]);

        if (422 === $status) {
            self::assertSame('expiresAt', self::assertApiError($response, 422, 'invalid_input')['details']['violations'][0]['field'] ?? null);
        } else {
            self::assertApiResponse($response, $status, self::WITH_SECRET);
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function expiries(): iterable
    {
        yield 'in 59 seconds' => ['2026-10-02T08:00:59Z', 422];
        yield 'in 1 minute' => ['2026-10-02T08:01:00Z', 201];
        yield 'in 366 days' => ['2027-10-03T08:00:00Z', 201];
        yield 'in 366 days and 1 second' => ['2027-10-03T08:00:01Z', 422];
    }

    #[TestDox('A 51st active token gives 422')]
    public function testActiveTokenLimit(): void
    {
        $client = static::createClient();
        $user = self::signIn($client);
        self::storeToken($user, revoked: true, name: 'revoked');
        $tokens = array_map(static fn (int $number): array => self::storeToken($user, name: "agent {$number}"), range(1, 50));

        $refused = self::apiRequest($client, 'POST', '/api/v1/tokens', ['name' => 'agent 51']);
        self::apiRequest($client, 'DELETE', "/api/v1/tokens/{$tokens[0][0]->getId()}");
        $accepted = self::apiRequest($client, 'POST', '/api/v1/tokens', ['name' => 'agent 51']);

        self::assertApiError($refused, 422, 'invalid_input');
        self::assertApiResponse($accepted, 201, self::WITH_SECRET);
    }

    #[TestDox('An unknown repositoryId gives 404')]
    public function testUnknownRepository(): void
    {
        $client = static::createClient();
        self::signIn($client);

        self::assertApiError(self::apiRequest($client, 'POST', '/api/v1/tokens', ['name' => 'codex desktop', 'repositoryId' => (string) new Ulid()]), 404, 'not_found');
    }
}
