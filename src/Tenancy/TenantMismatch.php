<?php

declare(strict_types=1);

namespace App\Tenancy;

final class TenantMismatch extends \LogicException
{
    public static function contextAlreadySet(): self
    {
        return new self('A different tenant context is set. Call TenantContext::reset() before you set another tenant.');
    }

    /**
     * @param class-string $entityClass
     */
    public static function writeOf(string $entityClass): self
    {
        return new self(\sprintf('A flush writes a %s row of another tenant. Only TenantFilter::suspend() writes across tenants.', $entityClass));
    }
}
