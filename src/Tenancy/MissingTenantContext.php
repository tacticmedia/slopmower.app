<?php

declare(strict_types=1);

namespace App\Tenancy;

final class MissingTenantContext extends \LogicException
{
    public static function notSet(): self
    {
        return new self('No tenant context is set.');
    }

    /**
     * @param class-string $entityClass
     */
    public static function forQueryOn(string $entityClass): self
    {
        return new self(\sprintf('A query on %s needs a tenant context. Only TenantFilter::suspend() runs tenantless queries.', $entityClass));
    }
}
