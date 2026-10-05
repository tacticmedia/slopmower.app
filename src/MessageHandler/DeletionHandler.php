<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Entity\Repository;
use App\Entity\Tenant;
use App\EventStore\CommandExecutor;
use App\Git\RepositoryLock;
use App\Git\TenantDataPaths;
use App\Message\DeleteCloneJob;
use App\Message\PurgeTenantJob;
use App\Repository\DeletionSql;
use App\Repository\RepositoryRepository;
use App\Repository\TenantRepository;
use App\Tenancy\TenantFilter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Ulid;

/**
 * Deletes a clone with its index (R6, D22), or every row and file of a tenant (A2, `app:tenant:purge`).
 */
final class DeletionHandler
{
    public function __construct(
        private readonly TenantFilter $tenantFilter,
        private readonly TenantRepository $tenants,
        private readonly RepositoryRepository $repositories,
        private readonly CommandExecutor $executor,
        private readonly DeletionSql $deletion,
        private readonly RepositoryLock $repositoryLock,
        private readonly TenantDataPaths $paths,
    ) {
    }

    /**
     * The index and the location cache go with the clone.
     */
    #[AsMessageHandler]
    public function handleDeleteClone(DeleteCloneJob $job): void
    {
        $this->deleteClone($job->tenantId, $job->repositoryId);
        $this->deletion->deleteRepositoryIndex($job->tenantId, $job->repositoryId);
    }

    #[AsMessageHandler]
    public function handlePurgeTenant(PurgeTenantJob $job): void
    {
        $this->purge($job->tenantId);
    }

    /**
     * The rows first, under the tenant row lock, then each clone under its repository lock, then the tenant directory.
     * A repeated run deletes what is left, also without the tenant row.
     */
    public function purge(TenantId $tenantId): void
    {
        $tenantUlid = Ulid::fromString($tenantId->toString());
        [$tenant, $repositoryIds] = $this->tenantFilter->suspend(fn (): array => [
            $this->tenants->find($tenantUlid),
            array_map(static fn (Repository $repository): RepositoryId => $repository->getId(), $this->repositories->findBy(['tenantId' => $tenantUlid])),
        ]);

        if ($tenant instanceof Tenant) {
            $this->executor->delete($tenantId, fn () => $this->deletion->deleteTenant($tenantId));
        } else {
            $this->deletion->deleteTenant($tenantId);
        }

        foreach ($repositoryIds as $repositoryId) {
            $this->deleteClone($tenantId, $repositoryId);
        }

        new Filesystem()->remove($this->paths->tenantPath($tenantId));
    }

    /**
     * Deletes the clone with its pin refs. Waits for a running clone or fetch, which cannot start again: the row is
     * gone, or its access is removed.
     */
    private function deleteClone(TenantId $tenantId, RepositoryId $repositoryId): void
    {
        $lock = $this->repositoryLock->acquire($repositoryId);

        try {
            new Filesystem()->remove($this->paths->clonePath($tenantId, $repositoryId));
        } finally {
            $lock->release();
        }
    }
}
