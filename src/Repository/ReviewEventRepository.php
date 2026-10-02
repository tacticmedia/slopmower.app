<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Identity\ReviewId;
use App\Entity\ReviewEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<ReviewEvent>
 */
final class ReviewEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReviewEvent::class);
    }

    /**
     * @return list<ReviewEvent> In seq order
     */
    public function findAfter(ReviewId $reviewId, int $cursor, int $limit): array
    {
        return $this->createQueryBuilder('event')
            ->where('event.reviewId = :reviewId')
            ->andWhere('event.seq > :cursor')
            ->orderBy('event.seq')
            ->setMaxResults($limit)
            ->setParameter('reviewId', Ulid::fromString($reviewId->toString()), UlidType::NAME)
            ->setParameter('cursor', $cursor)
            ->getQuery()
            ->getResult();
    }
}
