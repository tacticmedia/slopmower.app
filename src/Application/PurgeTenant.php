<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Entity\Repository;
use App\Entity\Tenant;
use App\EventStore\CommandExecutor;
use App\Git\TenantDataPaths;
use App\Message\PurgeTenantJob;
use App\Repository\DeletionSql;
use App\Repository\RepositoryRepository;
use App\Repository\TenantRepository;
use App\Tenancy\TenantFilter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Ulid;

/**
 * Deletes every row and file of a tenant: the rows first, under the tenant row lock, then each clone under its
 * repository lock, then the tenant directory. A repeated run deletes what is left, also without the tenant row.
 */
final class PurgeTenant
{
    public function __construct(
        private readonly TenantFilter $tenantFilter,
        private readonly TenantRepository $tenants,
        private readonly RepositoryRepository $repositories,
        private readonly CommandExecutor $executor,
        private readonly DeletionSql $deletion,
        private readonly FetchRepository $fetchRepository,
        private readonly TenantDataPaths $paths,
    ) {
    }

    #[AsMessageHandler]
    public function handle(PurgeTenantJob $job): void
    {
        $this->purge($job->tenantId);
    }

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
            $this->fetchRepository->deleteClone($tenantId, $repositoryId);
        }

        new Filesystem()->remove($this->paths->tenantPath($tenantId));
    }
}
