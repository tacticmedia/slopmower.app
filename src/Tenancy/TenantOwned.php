<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\Identity\TenantId;

/**
 * Maps the tenant to a non-null `tenant_id` column, which {@see TenantSqlFilter} scopes.
 */
interface TenantOwned
{
    public function getTenantId(): TenantId;
}
