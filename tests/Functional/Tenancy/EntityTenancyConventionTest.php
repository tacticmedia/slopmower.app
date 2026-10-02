<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Entity\Tenant;
use App\Tenancy\TenantOwned;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EntityTenancyConventionTest extends KernelTestCase
{
    private const array EXCEPTIONS = [Tenant::class];

    #[TestDox('Every entity except the listed exceptions implements TenantOwned and maps a non-null tenant_id')]
    public function testEveryEntityIsTenantScoped(): void
    {
        $allMetadata = self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata();
        $checked = 0;

        foreach ($allMetadata as $metadata) {
            if (\in_array($metadata->getName(), self::EXCEPTIONS, true)) {
                continue;
            }

            self::assertTrue($metadata->getReflectionClass()->implementsInterface(TenantOwned::class), "{$metadata->getName()} does not implement TenantOwned.");
            self::assertTrue($metadata->hasField('tenantId'), "{$metadata->getName()} maps no tenantId field.");
            self::assertSame('tenant_id', $metadata->getColumnName('tenantId'), "{$metadata->getName()} maps tenantId to another column.");
            self::assertSame(UlidType::NAME, $metadata->getTypeOfField('tenantId'), "{$metadata->getName()} maps tenantId with another type than ulid.");
            self::assertFalse($metadata->isNullable('tenantId'), "{$metadata->getName()} maps a nullable tenant_id.");
            ++$checked;
        }

        self::assertGreaterThan(0, $checked);
    }
}
