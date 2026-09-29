<?php

declare(strict_types=1);

namespace App\Tenancy;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * UnitOfWork writes bypass {@see TenantSqlFilter}. Outside {@see TenantFilter::suspend()}, every written row
 * belongs to the context tenant.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class TenantWriteGuard
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        if ($entityManager->getFilters()->isSuspended(TenantSqlFilter::NAME)) {
            return;
        }

        $unitOfWork = $entityManager->getUnitOfWork();
        $writes = [
            ...$unitOfWork->getScheduledEntityInsertions(),
            ...$unitOfWork->getScheduledEntityUpdates(),
            ...$unitOfWork->getScheduledEntityDeletions(),
        ];
        foreach ($writes as $entity) {
            // A removed reference of another tenant fails to load here: EntityNotFoundException or MissingTenantContext.
            $ownerTenantId = TenantOwnership::tenantIdOf($entity);
            if (null !== $ownerTenantId && !$ownerTenantId->equals($this->tenantContext->tenantId())) {
                throw TenantMismatch::writeOf($entity::class);
            }
        }
    }
}
