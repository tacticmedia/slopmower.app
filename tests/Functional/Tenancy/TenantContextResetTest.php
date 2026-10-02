<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Domain\Identity\RepositoryId;
use App\Repository\UserRepository;
use App\Tenancy\MissingTenantContext;
use App\Tenancy\RepositoryScopeSqlFilter;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantContextResetTest extends KernelTestCase
{
    use ResetDatabase;

    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $user = TenantFixtures::of(self::getContainer())->create();
        $this->tenantContext = self::getContainer()->get(TenantContext::class);
        $this->tenantContext->set($user->getTenantId());
        self::assertCount(1, self::getContainer()->get(UserRepository::class)->findAll());
    }

    #[TestDox('The services resetter clears the tenant context')]
    public function testServicesResetterClearsTheTenantContext(): void
    {
        self::getContainer()->get('services_resetter')->reset();

        $this->expectException(MissingTenantContext::class);

        $this->tenantContext->get();
    }

    #[TestDox('The services resetter drops the repository scope')]
    public function testServicesResetterDropsTheRepositoryScope(): void
    {
        $this->tenantContext->restrictToRepository(RepositoryId::generate());

        self::getContainer()->get('services_resetter')->reset();

        self::assertFalse(self::getContainer()->get(EntityManagerInterface::class)->getFilters()->isEnabled(RepositoryScopeSqlFilter::NAME));
    }

    #[TestDox('After the services resetter runs, an ORM query on a tenant table throws')]
    public function testOrmQueryAfterServicesResetterThrows(): void
    {
        self::getContainer()->get('services_resetter')->reset();

        $this->expectException(MissingTenantContext::class);

        self::getContainer()->get(UserRepository::class)->findAll();
    }
}
