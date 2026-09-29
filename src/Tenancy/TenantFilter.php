<?php

declare(strict_types=1);

namespace App\Tenancy;

use Doctrine\ORM\EntityManagerInterface;

/**
 * The only way to run tenantless ORM queries and writes.
 */
final class TenantFilter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * FilterCollection::suspend() and restore() keep the filter instance, so the tenant parameter survives.
     * Afterwards {@see TenantContext::detachOtherTenants()} runs, so the callable returns IDs or scalars,
     * not entities of another tenant, and flushes its own changes to them.
     *
     * @template T
     *
     * @param callable(): T $callable
     *
     * @return T
     */
    public function suspend(callable $callable): mixed
    {
        $filters = $this->entityManager->getFilters();
        // FilterCollection::suspend() throws for a suspended filter.
        if ($filters->isSuspended(TenantSqlFilter::NAME)) {
            return $callable();
        }

        $filters->suspend(TenantSqlFilter::NAME);
        try {
            return $callable();
        } finally {
            $filters->restore(TenantSqlFilter::NAME);
            $this->tenantContext->detachOtherTenants();
        }
    }
}
