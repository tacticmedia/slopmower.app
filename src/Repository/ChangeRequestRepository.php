<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Entity\ChangeRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChangeRequest>
 */
final class ChangeRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChangeRequest::class);
    }

    /**
     * Overwrites a managed instance with the stored row, so a read under the tenant lock sees the last commit.
     */
    public function findFresh(RequestId $id): ?ChangeRequest
    {
        return $this->createQueryBuilder('request')
            ->where('request.id = :id')
            ->setParameter('id', $id, RequestId::class)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * @return list<ChangeRequest> Overwrites managed instances with the stored rows
     */
    public function findFreshOfReview(ReviewId $reviewId): array
    {
        return $this->createQueryBuilder('request')
            ->where('request.review = :review')
            ->setParameter('review', $reviewId, ReviewId::class)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }
}
