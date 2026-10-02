<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class TenantSqlFilterTest extends KernelTestCase
{
    use ResetDatabase;

    #[TestDox('With context A, findAll() returns only A\'s rows')]
    public function testFindAllReturnsOnlyRowsOfTheContextTenant(): void
    {
        $alice = TenantFixtures::of(self::getContainer())->withLogin('alice')->create();
        TenantFixtures::of(self::getContainer())->withLogin('bob')->create();

        self::getContainer()->get(TenantContext::class)->set($alice->getTenantId());

        $logins = array_map(static fn (User $user): string => $user->getLogin(), self::getContainer()->get(UserRepository::class)->findAll());
        self::assertSame(['alice'], $logins);
    }

    #[TestDox('With context A, find() of a B ID returns null')]
    public function testFindOfAnotherTenantsIdReturnsNull(): void
    {
        $alice = TenantFixtures::of(self::getContainer())->withLogin('alice')->create();
        $bob = TenantFixtures::of(self::getContainer())->withLogin('bob')->create();

        self::getContainer()->get(TenantContext::class)->set($alice->getTenantId());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($entityManager->find(User::class, Ulid::fromString($bob->getId()->toString())));
        self::assertNull($entityManager->find(Tenant::class, Ulid::fromString($bob->getTenantId()->toString())));
    }
}
