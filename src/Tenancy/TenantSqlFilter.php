<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Entity\Tenant;
use App\Entity\TenantOwned;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Does not cover DBAL queries or identity-map hits.
 */
final class TenantSqlFilter extends SQLFilter
{
    public const string NAME = 'tenant';
    public const string PARAMETER = 'tenantId';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $column = match (true) {
            Tenant::class === $targetEntity->getName() => 'id',
            $targetEntity->getReflectionClass()->implementsInterface(TenantOwned::class) => 'tenant_id',
            default => null,
        };

        if (null === $column) {
            return '';
        }

        if (!$this->hasParameter(self::PARAMETER)) {
            throw new MissingTenantContext();
        }

        return "{$targetTableAlias}.{$column} = {$this->getParameter(self::PARAMETER)}";
    }
}
