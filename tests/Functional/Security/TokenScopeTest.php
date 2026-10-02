<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Review;
use App\Tenancy\TenantContext;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;

final class TokenScopeTest extends WebTestCase
{
    use ApiFixtures;
    use JsonApiAssertions;
    use ResetDatabase;
    use ReviewDataFixtures;

    #[TestDox('A scoped token lists only its repository')]
    public function testListsOnlyItsRepository(): void
    {
        $client = static::createClient();
        $user = TenantFixtures::of(self::getContainer())->create();
        $alpha = self::storeRepository($user->getTenantId(), 'alpha');
        self::storeRepository($user->getTenantId(), 'beta');
        [, $secret] = self::storeToken($user, $alpha);

        $page = self::assertApiResponse(self::bearerRequest($client, $secret, 'GET', '/api/v1/repositories'), 200, 'lists.schema.json#/$defs/RepositoryPage');

        self::assertIsArray($page['items']);
        self::assertSame([$alpha->getId()->toString()], array_column($page['items'], 'id'));
    }

    #[TestDox('A scoped token gets 404 for another repository and its reviews')]
    public function testOtherRepositoryAndItsReviewsAreNotFound(): void
    {
        $client = static::createClient();
        $user = TenantFixtures::of(self::getContainer())->create();
        self::getContainer()->get(TenantContext::class)->set($user->getTenantId());
        $scenario = RequestScenario::inState('open');
        self::persistScenario($user->getTenantId(), $scenario);
        $alpha = self::storeRepository($user->getTenantId(), 'alpha');
        [, $secret] = self::storeToken($user, $alpha);
        $reviewId = Ulid::fromString($scenario->review()->id->toString());
        $billingId = self::entityManager()->find(Review::class, $reviewId)?->getRepository()->getId()->toString() ?? self::fail('No review.');

        self::assertApiError(self::bearerRequest($client, $secret, 'GET', "/api/v1/repositories/{$billingId}"), 404, 'not_found');
        self::assertApiError(self::bearerRequest($client, $secret, 'POST', "/api/v1/repositories/{$billingId}/fetch"), 404, 'not_found');
        self::assertApiError(self::bearerRequest($client, $secret, 'GET', "/api/v1/reviews/{$scenario->review()->id}"), 404, 'not_found');
        self::assertApiResponse(self::bearerRequest($client, $secret, 'GET', "/api/v1/repositories/{$alpha->getId()}"), 200, 'repository.schema.json');
    }

    #[TestDox('/api/v1/me shows the scope of the token')]
    public function testMeShowsTheScope(): void
    {
        $client = static::createClient();
        $user = TenantFixtures::of(self::getContainer())->create();
        $alpha = self::storeRepository($user->getTenantId(), 'alpha');
        [, $secret] = self::storeToken($user, $alpha);

        $me = self::assertApiResponse(self::bearerRequest($client, $secret, 'GET', '/api/v1/me'), 200, 'me.schema.json');

        self::assertIsArray($me['token']);
        self::assertSame(
            ['repository', $alpha->getId()->toString(), 'alpha'],
            [$me['token']['scope'], $me['token']['repositoryId'], $me['token']['repositoryName']],
        );
    }
}
