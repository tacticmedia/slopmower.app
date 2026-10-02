<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CrossTenantTokenTest extends WebTestCase
{
    use ApiFixtures;
    use JsonApiAssertions;
    use ResetDatabase;

    #[TestDox('A token of tenant A never reads data of tenant B')]
    public function testTokenReadsOnlyItsTenant(): void
    {
        $client = static::createClient();
        $tenantA = TenantFixtures::of(self::getContainer())->withLogin('a')->create();
        $tenantB = TenantFixtures::of(self::getContainer())->withLogin('b')->create();
        $repositoryA = self::storeRepository($tenantA->getTenantId(), 'alpha');
        $repositoryB = self::storeRepository($tenantB->getTenantId(), 'billing');
        [, $secret] = self::storeToken($tenantA);
        $idsOfB = [$tenantB->getTenantId()->toString(), $tenantB->getId()->toString(), $repositoryB->getId()->toString()];

        self::assertApiError(self::bearerRequest($client, $secret, 'GET', "/api/v1/repositories/{$repositoryB->getId()}"), 404, 'not_found');
        self::assertApiError(self::bearerRequest($client, $secret, 'POST', "/api/v1/repositories/{$repositoryB->getId()}/fetch"), 404, 'not_found');
        $page = self::assertApiResponse(self::bearerRequest($client, $secret, 'GET', '/api/v1/repositories'), 200, 'lists.schema.json#/$defs/RepositoryPage');
        $me = self::bearerRequest($client, $secret, 'GET', '/api/v1/me');

        self::assertIsArray($page['items']);
        self::assertSame([$repositoryA->getId()->toString()], array_column($page['items'], 'id'));
        self::assertApiResponse($me, 200, 'me.schema.json');

        foreach ($idsOfB as $id) {
            self::assertStringNotContainsStringIgnoringCase($id, (string) $me->getContent());
        }
    }

    #[TestDox('Revoking a token ID of tenant B gives 404')]
    public function testRevokingAnotherTenantsTokenIsNotFound(): void
    {
        $client = static::createClient();
        $tenantB = TenantFixtures::of(self::getContainer())->withLogin('b')->create();
        [$tokenB, $secretB] = self::storeToken($tenantB);
        self::signIn($client, 'a');

        self::assertApiError(self::apiRequest($client, 'DELETE', "/api/v1/tokens/{$tokenB->getId()}"), 404, 'not_found');
        self::assertNull(self::getContainer()->get(Connection::class)->fetchOne('SELECT revoked_at FROM api_token'));
        self::assertSame(200, self::bearerRequest($client, $secretB, 'GET', '/api/v1/me')->getStatusCode());
    }
}
