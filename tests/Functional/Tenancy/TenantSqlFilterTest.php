<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Entity\User;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantSqlFilterTest extends KernelTestCase
{
    use ResetDatabase;

    #[TestDox("With context A, findAll() returns only A's rows")]
    public function testFindAllReturnsOnlyTheRowsOfTheContextTenant(): void
    {
        $fixtures = TenantFixtures::fromContainer(self::getContainer());
        $alice = $fixtures->localUser('alice');
        $fixtures->localUser('bob');
        $fixtures->gitHubUser('carol', 3);
        self::getContainer()->get(TenantContext::class)->set($alice->getTenantId());

        $users = self::getContainer()->get(UserRepository::class)->findAll();

        self::assertSame(
            [$alice->getId()->toString()],
            array_map(static fn (User $user): string => $user->getId()->toString(), $users),
        );
    }

    #[TestDox('With context A, find() of a B ID returns null')]
    public function testFindOfAnotherTenantsIdReturnsNull(): void
    {
        $fixtures = TenantFixtures::fromContainer(self::getContainer());
        $alice = $fixtures->localUser('alice');
        $bob = $fixtures->localUser('bob');
        self::getContainer()->get(TenantContext::class)->set($alice->getTenantId());
        $users = self::getContainer()->get(UserRepository::class);

        self::assertNull($users->find($bob->getId()->toUlid()));
        self::assertSame('alice', $users->find($alice->getId()->toUlid())?->getLogin());
    }

    #[TestDox("With context A, a DQL join of two tenant tables returns only A's rows")]
    public function testJoinReturnsOnlyTheRowsOfTheContextTenant(): void
    {
        $fixtures = TenantFixtures::fromContainer(self::getContainer());
        $alice = $fixtures->localUser('alice');
        $fixtures->localUser('bob');
        self::getContainer()->get(TenantContext::class)->set($alice->getTenantId());

        $rows = self::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('SELECT u.login AS userLogin, v.login AS otherLogin FROM '.User::class.' u, '.User::class.' v')
            ->getArrayResult();

        self::assertSame([['userLogin' => 'alice', 'otherLogin' => 'alice']], $rows);
    }

    #[TestDox('With context A, find() of tenant B returns null')]
    public function testFindOfAnotherTenantReturnsNull(): void
    {
        $fixtures = TenantFixtures::fromContainer(self::getContainer());
        $alice = $fixtures->localUser('alice');
        $bob = $fixtures->localUser('bob');
        self::getContainer()->get(TenantContext::class)->set($alice->getTenantId());
        $tenants = self::getContainer()->get(TenantRepository::class);

        self::assertNull($tenants->find($bob->getTenantId()->toUlid()));
        $ownTenant = $tenants->find($alice->getTenantId()->toUlid());
        self::assertNotNull($ownTenant);
        self::assertTrue($alice->getTenantId()->equals($ownTenant->getId()));
    }
}
