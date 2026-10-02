<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Identity\TenantId;
use App\Entity\ChangeRequest;
use App\Entity\CommitLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<CommitLink>
 */
final class CommitLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommitLink::class);
    }

    /**
     * A bulk delete: it bypasses the identity map.
     */
    public function deleteOfTenant(TenantId $tenantId): void
    {
        $this->createQueryBuilder('link')
            ->delete()
            ->where('link.tenantId = :tenantId')
            ->setParameter('tenantId', Ulid::fromString($tenantId->toString()), UlidType::NAME)
            ->getQuery()
            ->execute();
    }

    /**
     * Overwrites managed instances with the stored rows, so a read under the tenant lock sees the last commit.
     *
     * @return list<CommitLink>
     */
    public function findFreshOf(ChangeRequest $request): array
    {
        return $this->createQueryBuilder('link')
            ->where('link.request = :request')
            ->setParameter('request', Ulid::fromString($request->getId()->toString()), UlidType::NAME)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }
}
