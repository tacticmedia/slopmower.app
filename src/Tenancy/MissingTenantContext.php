<?php

declare(strict_types=1);

namespace App\Tenancy;

final class MissingTenantContext extends \LogicException
{
    public function __construct()
    {
        parent::__construct('No tenant context. Set it through TenantContext, or run tenantless ORM queries through TenantFilter::suspend().');
    }
}
