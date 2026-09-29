<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFilter;
use App\Tenancy\TenantMismatch;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantContextSetTest extends KernelTestCase
{
    use ResetDatabase;

    private User $alice;
    private User $bob;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $fixtures = TenantFixtures::fromContainer(self::getContainer());
        $this->alice = $fixtures->localUser('alice');
        $this->bob = $fixtures->localUser('bob');
        $this->tenantContext = self::getContainer()->get(TenantContext::class);
    }

    #[TestDox('Setting the same tenant again keeps it, and setting a different tenant without reset() throws')]
    public function testSettingADifferentTenantWithoutResetThrows(): void
    {
        $this->tenantContext->set($this->alice->getTenantId());
        $this->tenantContext->set($this->alice->getTenantId());

        $thrown = null;
        try {
            $this->tenantContext->set($this->bob->getTenantId());
        } catch (TenantMismatch $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(TenantMismatch::class, $thrown);
        self::assertTrue($this->alice->getTenantId()->equals($this->tenantContext->tenantId()));
        self::assertSame(
            ['alice'],
            array_map(static fn (User $user): string => $user->getLogin(), self::getContainer()->get(UserRepository::class)->findAll()),
        );
    }

    #[TestDox('set() detaches the entities of other tenants that a tenantless suspend() loaded')]
    public function testSetDetachesTheEntitiesOfOtherTenants(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $alice = $this->alice;
        $bob = $this->bob;
        self::getContainer()->get(TenantFilter::class)->suspend(static function () use ($entityManager, $alice, $bob): void {
            foreach ([$alice, $bob] as $user) {
                $entityManager->find(User::class, $user->getId()->toUlid());
                $entityManager->find(Tenant::class, $user->getTenantId()->toUlid());
            }
        });

        $this->tenantContext->set($alice->getTenantId());

        self::assertNull($entityManager->find(User::class, $bob->getId()->toUlid()));
        self::assertNull($entityManager->find(Tenant::class, $bob->getTenantId()->toUlid()));
        self::assertSame('alice', $entityManager->find(User::class, $alice->getId()->toUlid())?->getLogin());
    }
}
