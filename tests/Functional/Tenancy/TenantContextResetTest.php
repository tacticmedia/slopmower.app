<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

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

    #[TestDox('After the services resetter runs, an ORM query on a tenant table throws')]
    public function testOrmQueryAfterServicesResetterThrows(): void
    {
        self::getContainer()->get('services_resetter')->reset();

        $this->expectException(MissingTenantContext::class);

        self::getContainer()->get(UserRepository::class)->findAll();
    }
}
