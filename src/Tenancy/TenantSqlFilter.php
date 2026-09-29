<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Entity\Tenant;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Scopes {@see TenantOwned} entities by {@see self::COLUMN} and {@see Tenant} by its ID.
 * Registered as {@see self::NAME} in config/packages/doctrine.yaml. {@see TenantContext::set()} sets the parameter.
 */
final class TenantSqlFilter extends SQLFilter
{
    public const string NAME = 'tenant';
    public const string PARAMETER = 'tenant_id';
    public const string COLUMN = 'tenant_id';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $column = match (true) {
            is_a($targetEntity->getName(), TenantOwned::class, true) => self::COLUMN,
            is_a($targetEntity->getName(), Tenant::class, true) => $targetEntity->getSingleIdentifierColumnName(),
            default => null,
        };
        if (null === $column) {
            return '';
        }

        if (!$this->hasParameter(self::PARAMETER)) {
            throw MissingTenantContext::forQueryOn($targetEntity->getName());
        }

        return $targetTableAlias.'.'.$column.' = '.$this->getParameter(self::PARAMETER);
    }
}
