<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Repository\UserRepository;
use App\Tenancy\MissingTenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FailClosedTest extends KernelTestCase
{
    use ResetDatabase;

    #[TestDox('A query without a tenant context throws')]
    public function testQueryWithoutTenantContextThrows(): void
    {
        TenantFixtures::of(self::getContainer())->create();

        $this->expectException(MissingTenantContext::class);

        self::getContainer()->get(UserRepository::class)->findAll();
    }
}
