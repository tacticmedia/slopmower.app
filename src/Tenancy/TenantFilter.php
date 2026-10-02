<?php

declare(strict_types=1);

namespace App\Tenancy;

use Doctrine\ORM\EntityManagerInterface;

/**
 * The only way to run tenantless ORM queries.
 */
final class TenantFilter
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * suspend() and restore() keep the filter instance with its parameter. disable() and enable() would drop it.
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
        }
    }
}
