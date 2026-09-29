<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tenancy\MissingTenantContext;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantContextResetTest extends KernelTestCase
{
    use ResetDatabase;

    #[TestDox('The services resetter clears the tenant context')]
    public function testServicesResetterClearsTheTenantContext(): void
    {
        $alice = TenantFixtures::fromContainer(self::getContainer())->localUser('alice');
        self::getContainer()->get(TenantContext::class)->set($alice->getTenantId());
        self::assertTrue($alice->getTenantId()->equals(self::getContainer()->get(TenantContext::class)->tenantId()));

        self::getContainer()->get('services_resetter')->reset();

        $this->expectException(MissingTenantContext::class);
        self::getContainer()->get(TenantContext::class)->tenantId();
    }

    #[TestDox('After the services resetter runs, an ORM query on a tenant table throws')]
    public function testOrmQueryOnATenantTableThrowsAfterTheServicesResetter(): void
    {
        $alice = TenantFixtures::fromContainer(self::getContainer())->localUser('alice');
        self::getContainer()->get(TenantContext::class)->set($alice->getTenantId());
        $users = self::getContainer()->get(UserRepository::class);
        self::assertCount(1, $users->findAll());

        self::getContainer()->get('services_resetter')->reset();

        $this->expectException(MissingTenantContext::class);
        $users->findAll();
    }

    #[TestDox('A tenant set after the services resetter scopes queries')]
    public function testTenantSetAfterTheServicesResetterScopesQueries(): void
    {
        $fixtures = TenantFixtures::fromContainer(self::getContainer());
        $alice = $fixtures->localUser('alice');
        $bob = $fixtures->localUser('bob');
        $tenantContext = self::tenantContext();
        $tenantContext->set($alice->getTenantId());

        self::getContainer()->get('services_resetter')->reset();
        $tenantContext->set($bob->getTenantId());

        self::assertTrue($bob->getTenantId()->equals($tenantContext->tenantId()));
        self::assertSame(['bob'], self::logins(self::getContainer()->get(UserRepository::class)->findAll()));
    }

    #[TestDox('After reset(), find() of an entity that was loaded before throws')]
    public function testFindAfterResetThrows(): void
    {
        $alice = TenantFixtures::fromContainer(self::getContainer())->localUser('alice');
        $tenantContext = self::tenantContext();
        $users = self::getContainer()->get(UserRepository::class);
        $tenantContext->set($alice->getTenantId());
        self::assertNotNull($users->find($alice->getId()->toUlid()));

        $tenantContext->reset();

        $this->expectException(MissingTenantContext::class);
        $users->find($alice->getId()->toUlid());
    }

    #[TestDox('After reset() and set(B), find() of an A ID returns null')]
    public function testFindOfAnAIdAfterResetAndSetOfBReturnsNull(): void
    {
        $fixtures = TenantFixtures::fromContainer(self::getContainer());
        $alice = $fixtures->localUser('alice');
        $bob = $fixtures->localUser('bob');
        $tenantContext = self::tenantContext();
        $users = self::getContainer()->get(UserRepository::class);
        $tenantContext->set($alice->getTenantId());
        self::assertNotNull($users->find($alice->getId()->toUlid()));

        $tenantContext->reset();
        $tenantContext->set($bob->getTenantId());

        self::assertNull($users->find($alice->getId()->toUlid()));
    }

    private static function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    /**
     * @param list<User> $users
     *
     * @return list<string>
     */
    private static function logins(array $users): array
    {
        return array_map(static fn (User $user): string => $user->getLogin(), $users);
    }
}
