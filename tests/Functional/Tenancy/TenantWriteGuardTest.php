<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use App\Entity\Tenant;
use App\Entity\User;
use App\Tenancy\MissingTenantContext;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFilter;
use App\Tenancy\TenantMismatch;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantWriteGuardTest extends KernelTestCase
{
    use ResetDatabase;

    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        $fixtures = TenantFixtures::fromContainer(self::getContainer());
        $this->alice = $fixtures->localUser('alice');
        $this->bob = $fixtures->localUser('bob');
    }

    #[TestDox('With context A, removing a reference to a B row throws and keeps the row')]
    public function testRemovingAReferenceToAnotherTenantsRowThrows(): void
    {
        self::getContainer()->get(TenantContext::class)->set($this->alice->getTenantId());
        $entityManager = self::entityManager();
        $entityManager->remove(self::reference($this->bob));

        self::assertFlushThrows(EntityNotFoundException::class);
    }

    /**
     * @return iterable<string, array{\Closure(EntityManagerInterface, User, User, \DateTimeImmutable): void}>
     */
    public static function tenantlessWrites(): iterable
    {
        yield 'removal of a user reference' => [static function (EntityManagerInterface $entityManager, User $alice): void {
            $entityManager->remove(self::reference($alice));
        }];
        yield 'user insert' => [static function (EntityManagerInterface $entityManager, User $alice, User $bob, \DateTimeImmutable $now): void {
            $entityManager->persist(User::local(UserId::generate(), $alice->getTenantId(), 'carol', $now));
        }];
        yield 'tenant insert' => [static function (EntityManagerInterface $entityManager, User $alice, User $bob, \DateTimeImmutable $now): void {
            $entityManager->persist(new Tenant(TenantId::generate(), $now));
        }];
    }

    /**
     * @param \Closure(EntityManagerInterface, User, User, \DateTimeImmutable): void $write
     */
    #[DataProvider('tenantlessWrites')]
    #[TestDox('Without a tenant context, a flush that writes a tenant row throws')]
    public function testFlushWithoutTenantContextThrows(\Closure $write): void
    {
        $write(self::entityManager(), $this->alice, $this->bob, self::now());

        self::assertFlushThrows(MissingTenantContext::class);
    }

    /**
     * @return iterable<string, array{\Closure(EntityManagerInterface, User, User, \DateTimeImmutable): void}>
     */
    public static function writesOfAnotherTenant(): iterable
    {
        yield 'user insert for B' => [static function (EntityManagerInterface $entityManager, User $alice, User $bob, \DateTimeImmutable $now): void {
            $entityManager->persist(User::local(UserId::generate(), $bob->getTenantId(), 'carol', $now));
        }];
        yield 'tenant insert' => [static function (EntityManagerInterface $entityManager, User $alice, User $bob, \DateTimeImmutable $now): void {
            $entityManager->persist(new Tenant(TenantId::generate(), $now));
        }];
        yield 'removal of B that suspend() scheduled' => [static function (EntityManagerInterface $entityManager, User $alice, User $bob): void {
            self::getContainer()->get(TenantFilter::class)->suspend(static function () use ($entityManager, $bob): void {
                $entityManager->remove($entityManager->find(User::class, $bob->getId()->toUlid()) ?? throw new \LogicException('Bob is missing.'));
            });
        }];
        yield 'insert for B that suspend() persisted' => [static function (EntityManagerInterface $entityManager, User $alice, User $bob, \DateTimeImmutable $now): void {
            self::getContainer()->get(TenantFilter::class)->suspend(static function () use ($entityManager, $bob, $now): void {
                $entityManager->persist(User::local(UserId::generate(), $bob->getTenantId(), 'carol', $now));
            });
        }];
    }

    /**
     * @param \Closure(EntityManagerInterface, User, User, \DateTimeImmutable): void $write
     */
    #[DataProvider('writesOfAnotherTenant')]
    #[TestDox("With context A, a flush that writes another tenant's row throws")]
    public function testFlushOfAnotherTenantsRowThrows(\Closure $write): void
    {
        self::getContainer()->get(TenantContext::class)->set($this->alice->getTenantId());

        $write(self::entityManager(), $this->alice, $this->bob, self::now());

        self::assertFlushThrows(TenantMismatch::class);
    }

    #[TestDox("With context A, a flush writes A's rows")]
    public function testFlushWritesTheRowsOfTheContextTenant(): void
    {
        self::getContainer()->get(TenantContext::class)->set($this->alice->getTenantId());
        $entityManager = self::entityManager();

        $entityManager->remove(self::reference($this->alice));
        $entityManager->persist(User::local(UserId::generate(), $this->alice->getTenantId(), 'carol', self::now()));
        $entityManager->flush();

        self::assertSame(['bob', 'carol'], self::storedLogins());
    }

    #[TestDox("Inside TenantFilter::suspend(), a flush writes any tenant's rows")]
    public function testFlushInsideSuspendWritesAnyTenantsRows(): void
    {
        self::getContainer()->get(TenantContext::class)->set($this->alice->getTenantId());
        $entityManager = self::entityManager();

        self::getContainer()->get(TenantFilter::class)->suspend(function () use ($entityManager): void {
            $entityManager->remove(self::reference($this->bob));
            $entityManager->flush();
        });

        self::assertSame(['alice'], self::storedLogins());
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    private static function assertFlushThrows(string $expected): void
    {
        $thrown = null;
        try {
            self::entityManager()->flush();
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf($expected, $thrown);
        self::assertSame(['alice', 'bob'], self::storedLogins());
        self::assertSame(2, self::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM tenant'));
    }

    /**
     * @return list<string>
     */
    private static function storedLogins(): array
    {
        return array_map(strval(...), self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT login FROM app_user ORDER BY login'));
    }

    private static function reference(User $user): User
    {
        return self::entityManager()->getReference(User::class, $user->getId()->toUlid()) ?? throw new \LogicException('No reference.');
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private static function now(): \DateTimeImmutable
    {
        return self::getContainer()->get(ClockInterface::class)->now();
    }
}
