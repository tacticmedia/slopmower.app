<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Security\SessionUser;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantSqlFilter;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TenantContextFromUserTest extends WebTestCase
{
    use ResetDatabase;

    #[TestDox('After sign-in, the filter parameter is the user\'s tenant')]
    public function testFilterParameterIsTheUsersTenant(): void
    {
        $client = self::createClient();
        $fixtures = TenantFixtures::fromContainer(self::getContainer());
        $fixtures->localUser('alice');
        $bob = $fixtures->localUser('bob');
        $client->loginUser(SessionUser::fromUser($bob), 'web');

        // A page that does not read the user, so only the listener loads it from the lazy firewall.
        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertSame(
            $entityManager->getConnection()->quote($bob->getTenantId()->toUlid()->toRfc4122()),
            $entityManager->getFilters()->getFilter(TenantSqlFilter::NAME)->getParameter(TenantSqlFilter::PARAMETER),
        );
        self::assertTrue($bob->getTenantId()->equals($container->get(TenantContext::class)->tenantId()));
    }
}
