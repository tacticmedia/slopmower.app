<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Domain\Identity\TenantId;
use App\Entity\MarketplaceAccount;
use App\Entity\Tenant;
use App\Entity\TenantOwned;
use App\Entity\WebhookDelivery;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EntityTenancyConventionTest extends KernelTestCase
{
    private const array EXCEPTIONS = [Tenant::class, WebhookDelivery::class, MarketplaceAccount::class];

    #[TestDox('Every entity except the listed exceptions implements TenantOwned and maps a non-null tenant_id')]
    public function testEveryEntityIsTenantScoped(): void
    {
        $allMetadata = self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata();
        $checked = 0;

        foreach ($allMetadata as $metadata) {
            if ($metadata->isEmbeddedClass || \in_array($metadata->getName(), self::EXCEPTIONS, true)) {
                continue;
            }

            self::assertTrue($metadata->getReflectionClass()->implementsInterface(TenantOwned::class), "{$metadata->getName()} does not implement TenantOwned.");
            self::assertTrue($metadata->hasField('tenantId'), "{$metadata->getName()} maps no tenantId field.");
            self::assertSame('tenant_id', $metadata->getColumnName('tenantId'), "{$metadata->getName()} maps tenantId to another column.");
            self::assertSame(TenantId::class, $metadata->getTypeOfField('tenantId'), "{$metadata->getName()} maps tenantId with another type than TenantId.");
            self::assertFalse($metadata->isNullable('tenantId'), "{$metadata->getName()} maps a nullable tenant_id.");
            ++$checked;
        }

        self::assertGreaterThan(0, $checked);
    }
}
