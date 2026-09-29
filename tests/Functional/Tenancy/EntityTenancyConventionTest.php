<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Entity\Tenant;
use App\Entity\User;
use App\Tenancy\TenantOwned;
use App\Tenancy\TenantSqlFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EntityTenancyConventionTest extends KernelTestCase
{
    /**
     * Entities that map no tenant_id. {@see TenantSqlFilter} scopes Tenant by its ID.
     */
    private const array EXCEPTIONS = [Tenant::class];

    #[TestDox('Every entity except the listed exceptions implements TenantOwned and maps a non-null tenant_id')]
    public function testEveryEntityIsTenantScoped(): void
    {
        $allMetadata = self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata();
        $entityClasses = array_map(static fn (ClassMetadata $metadata): string => $metadata->getName(), $allMetadata);
        self::assertSame([], array_values(array_diff(self::EXCEPTIONS, $entityClasses)), 'Each exception is an entity.');
        self::assertContains(User::class, $entityClasses);

        $violations = [];
        foreach ($allMetadata as $metadata) {
            $class = $metadata->getName();
            if (\in_array($class, self::EXCEPTIONS, true)) {
                continue;
            }
            if (!is_a($class, TenantOwned::class, true)) {
                $violations[] = $class.' does not implement TenantOwned';
            }
            if (!self::mapsNonNullTenantId($metadata)) {
                $violations[] = $class.' does not map a non-null ulid column '.TenantSqlFilter::COLUMN;
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private static function mapsNonNullTenantId(ClassMetadata $metadata): bool
    {
        foreach ($metadata->fieldMappings as $mapping) {
            if (TenantSqlFilter::COLUMN === $mapping->columnName) {
                return UlidType::NAME === $mapping->type && true !== $mapping->nullable;
            }
        }

        return false;
    }
}
