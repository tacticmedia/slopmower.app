<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\Identity\TenantId;

/**
 * The entity maps a non-null `ulid` column {@see TenantSqlFilter::COLUMN}, which {@see TenantSqlFilter} scopes.
 */
interface TenantOwned
{
    public function getTenantId(): TenantId;
}
