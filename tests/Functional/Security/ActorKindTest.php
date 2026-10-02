<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Security\CurrentActor;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ActorKindTest extends WebTestCase
{
    use ApiFixtures;
    use JsonApiAssertions;
    use ResetDatabase;

    /**
     * An empty body proves that the 403 comes before the body validation.
     */
    #[TestDox('A session-only endpoint called with a token gives 403 forbidden')]
    #[DataProvider('sessionOnlyEndpoints')]
    public function testSessionOnlyEndpointRefusesToken(string $method, string $path): void
    {
        $client = static::createClient();
        [$token, $secret] = self::storeToken(TenantFixtures::of(self::getContainer())->create());

        $response = self::bearerRequest($client, $secret, $method, str_replace('{tokenId}', $token->getId()->toString(), $path), 'GET' === $method ? null : []);

        self::assertSame(CurrentActor::SESSION_ONLY, self::assertApiError($response, 403, 'forbidden')['message']);
    }

    #[TestDox('An invisible ID gives 404 before the 403 of the actor kind')]
    public function testInvisibleIdComesFirst(): void
    {
        $client = static::createClient();
        [, $secret] = self::storeToken(TenantFixtures::of(self::getContainer())->create());
        [$otherToken] = self::storeToken(TenantFixtures::of(self::getContainer())->withLogin('other')->create());

        self::assertApiError(self::bearerRequest($client, $secret, 'DELETE', "/api/v1/tokens/{$otherToken->getId()}"), 404, 'not_found');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sessionOnlyEndpoints(): iterable
    {
        yield 'R2' => ['GET', '/api/v1/repositories/candidates'];
        yield 'R3' => ['POST', '/api/v1/repositories'];
        yield 'T1' => ['GET', '/api/v1/tokens'];
        yield 'T2' => ['POST', '/api/v1/tokens'];
        yield 'T3' => ['DELETE', '/api/v1/tokens/{tokenId}'];
    }
}
