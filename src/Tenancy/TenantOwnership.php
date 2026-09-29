<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\Identity\TenantId;
use App\Entity\Tenant;

final class TenantOwnership
{
    /**
     * Null for an entity that {@see TenantSqlFilter} does not scope.
     * On an uninitialized TenantOwned reference, the call loads the row through the SQL filter.
     */
    public static function tenantIdOf(object $entity): ?TenantId
    {
        return match (true) {
            $entity instanceof TenantOwned => $entity->getTenantId(),
            $entity instanceof Tenant => $entity->getId(),
            default => null,
        };
    }
}
