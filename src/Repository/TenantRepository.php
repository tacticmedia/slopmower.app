<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Identity\TenantId;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tenant>
 */
final class TenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    /**
     * False when the tenant is gone or being deleted.
     */
    public function isActive(TenantId $tenantId): bool
    {
        return null !== $this->activeQuery($tenantId)->getOneOrNullResult();
    }

    /**
     * {@see isActive()} with the row locked FOR SHARE until the transaction ends, so A2 and the purge wait for the
     * caller's writes. FOR KEY SHARE would not wait for the A2 mark.
     */
    public function lockActive(TenantId $tenantId): bool
    {
        return null !== $this->activeQuery($tenantId)->setLockMode(LockMode::PESSIMISTIC_READ)->getOneOrNullResult();
    }

    /**
     * @return Query<mixed, array{id: mixed}>
     */
    private function activeQuery(TenantId $tenantId): Query
    {
        return $this->createQueryBuilder('tenant')
            ->select('tenant.id')
            ->where('tenant.id = :id')
            ->andWhere('tenant.deletionRequestedAt IS NULL')
            ->setParameter('id', $tenantId, TenantId::class)
            ->getQuery();
    }
}
