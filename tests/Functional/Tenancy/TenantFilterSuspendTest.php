<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFilter;
use App\Tenancy\TenantSqlFilter;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class TenantFilterSuspendTest extends KernelTestCase
{
    use ResetDatabase;

    private TenantFilter $tenantFilter;
    private User $bob;

    protected function setUp(): void
    {
        $fixtures = TenantFixtures::fromContainer(self::getContainer());
        $alice = $fixtures->localUser('alice');
        $this->bob = $fixtures->localUser('bob');
        self::getContainer()->get(TenantContext::class)->set($alice->getTenantId());
        $this->tenantFilter = self::getContainer()->get(TenantFilter::class);
    }

    #[TestDox('Inside the callable, results cross tenants')]
    public function testResultsCrossTenantsInsideTheCallable(): void
    {
        self::assertSame(['alice', 'bob'], $this->tenantFilter->suspend(self::visibleLogins(...)));
    }

    #[TestDox('The filter is on again with the same tenant after the callable, also when the callable throws')]
    public function testFilterIsOnAgainWithTheSameTenantAfterTheCallable(): void
    {
        $this->tenantFilter->suspend(static fn (): null => null);

        self::assertFilterIsOnForAlice();

        $failure = new \RuntimeException('The callable failed.');
        $caught = null;
        try {
            $this->tenantFilter->suspend(static fn (): never => throw $failure);
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertSame($failure, $caught);
        self::assertFilterIsOnForAlice();
    }

    #[TestDox('A nested suspend works, and the filter is on again after the outer callable')]
    public function testNestedSuspendWorks(): void
    {
        $innerLogins = [];

        $outerLogins = $this->tenantFilter->suspend(function () use (&$innerLogins): array {
            $innerLogins = $this->tenantFilter->suspend(self::visibleLogins(...));

            return self::visibleLogins();
        });

        self::assertSame(['alice', 'bob'], $innerLogins);
        self::assertSame(['alice', 'bob'], $outerLogins);
        self::assertFilterIsOnForAlice();
    }

    #[TestDox('With context A, find() of a B entity that the callable loaded returns null')]
    public function testFindOfAnotherTenantsEntityThatTheCallableLoadedReturnsNull(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $bobId = $this->tenantFilter->suspend(static function () use ($entityManager): Ulid {
            $bob = $entityManager->getRepository(User::class)->findOneBy(['login' => 'bob']) ?? throw new \LogicException('Bob is missing.');

            return $bob->getId()->toUlid();
        });

        self::assertNull($entityManager->find(User::class, $bobId));
    }

    #[TestDox('A reference to a B row that the callable created stays unloaded after the callable')]
    public function testReferenceThatTheCallableCreatedStaysUnloaded(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $bobId = $this->bob->getId()->toUlid();

        $reference = $this->tenantFilter->suspend(
            static fn (): User => $entityManager->getReference(User::class, $bobId) ?? throw new \LogicException('No reference.'),
        );

        self::assertTrue($entityManager->isUninitializedObject($reference));
        self::assertFilterIsOnForAlice();
    }

    /**
     * @return list<string>
     */
    private static function visibleLogins(): array
    {
        return array_map(
            static fn (User $user): string => $user->getLogin(),
            self::getContainer()->get(UserRepository::class)->findBy([], ['login' => 'ASC']),
        );
    }

    private static function assertFilterIsOnForAlice(): void
    {
        self::assertTrue(self::getContainer()->get(EntityManagerInterface::class)->getFilters()->isEnabled(TenantSqlFilter::NAME));
        self::assertSame(['alice'], self::visibleLogins());
    }
}
