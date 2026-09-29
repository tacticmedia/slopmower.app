<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Entity\Tenant;
use App\Entity\User;
use App\Tenancy\MissingTenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class FailClosedTest extends KernelTestCase
{
    use ResetDatabase;

    /**
     * @return iterable<string, array{\Closure(EntityManagerInterface): mixed}>
     */
    public static function tenantTableQueries(): iterable
    {
        yield 'findAll()' => [static fn (EntityManagerInterface $entityManager): array => $entityManager->getRepository(User::class)->findAll()];
        yield 'find()' => [static fn (EntityManagerInterface $entityManager): ?User => $entityManager->find(User::class, new Ulid())];
        yield 'count()' => [static fn (EntityManagerInterface $entityManager): int => $entityManager->getRepository(User::class)->count()];
        yield 'DQL' => [static fn (EntityManagerInterface $entityManager): mixed => $entityManager->createQuery('SELECT u.login FROM '.User::class.' u')->getResult()];
        yield 'Tenant findAll()' => [static fn (EntityManagerInterface $entityManager): array => $entityManager->getRepository(Tenant::class)->findAll()];
        yield 'Tenant find()' => [static fn (EntityManagerInterface $entityManager): ?Tenant => $entityManager->find(Tenant::class, new Ulid())];
    }

    /**
     * @param \Closure(EntityManagerInterface): mixed $query
     */
    #[DataProvider('tenantTableQueries')]
    #[TestDox('A query without a tenant context throws')]
    public function testQueryWithoutTenantContextThrows(\Closure $query): void
    {
        TenantFixtures::fromContainer(self::getContainer())->localUser('alice');

        $this->expectException(MissingTenantContext::class);

        $query(self::getContainer()->get(EntityManagerInterface::class));
    }
}
