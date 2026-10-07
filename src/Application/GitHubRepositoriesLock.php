<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Identity\TenantId;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The tenant lock of the GitHub repository names, installations, and candidates.
 */
final class GitHubRepositoriesLock
{
    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenants,
    ) {
    }

    /**
     * Runs the work in a transaction that holds the tenant row lock of {@see TenantRepository::lockActive()}.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T|null Null for a tenant that is gone or being deleted, without the work. Work that must tell this apart
     *                returns a value other than null.
     */
    public function run(TenantId $tenantId, \Closure $work): mixed
    {
        $lock = $this->lockFactory->createLock("github-repositories:{$tenantId}", ttl: null);
        $lock->acquire(true);

        try {
            return $this->entityManager->wrapInTransaction(fn (): mixed => $this->tenants->lockActive($tenantId) ? $work() : null);
        } finally {
            $lock->release();
        }
    }
}
