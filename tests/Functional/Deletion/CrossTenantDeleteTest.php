<?php

declare(strict_types=1);

namespace App\Tests\Functional\Deletion;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Deletion\TenantRows;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * V13 and R6 with the IDs of another tenant. A2 names no ID: PurgeTenantTest shows that it leaves the other tenant
 * untouched.
 */
final class CrossTenantDeleteTest extends WebTestCase
{
    use ApiFixtures;
    use JsonApiAssertions;
    use ResetDatabase;
    use TenantRows;

    #[TestDox("A delete with another tenant's IDs gets 404 and deletes nothing")]
    public function testDeleteWithOtherTenantIdsGetsNotFound(): void
    {
        $client = static::createClient();
        $other = TenantFixtures::of(self::getContainer())->withLogin('other')->create();
        $otherReview = self::storeReview($other, 'billing');
        self::storeRequest($otherReview);
        self::storeToken($other, $otherReview->getRepository());
        self::signIn($client);
        $before = self::tenantRowCounts($other->getTenantId());

        self::assertApiError(self::apiRequest($client, 'DELETE', "/api/v1/reviews/{$otherReview->getId()}"), 404, 'not_found');
        self::assertApiError(self::apiRequest($client, 'DELETE', "/api/v1/repositories/{$otherReview->getRepository()->getId()}?confirm=billing"), 404, 'not_found');

        self::assertSame(['review' => 1, 'change_request' => 1, 'repository' => 1, 'api_token' => 1], self::tenantRowCountsOf($other->getTenantId(), ['review', 'change_request', 'repository', 'api_token']));
        self::assertSame($before, self::tenantRowCounts($other->getTenantId()));
    }
}
