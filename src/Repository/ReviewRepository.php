<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\ReviewId;
use App\Domain\Review\ReviewState;
use App\Entity\Review;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
final class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /**
     * Overwrites a managed instance with the stored row, so a read under the tenant lock sees the last commit.
     */
    public function findFresh(ReviewId $id): ?Review
    {
        return $this->createQueryBuilder('review')
            ->where('review.id = :id')
            ->setParameter('id', $id, ReviewId::class)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * @param ReviewState|null $state Null for every state
     *
     * @return list<Review>
     */
    public function findOfRepository(RepositoryId $repositoryId, ?ReviewState $state = null): array
    {
        $builder = $this->createQueryBuilder('review')
            ->where('review.repository = :repository')
            ->setParameter('repository', $repositoryId, RepositoryId::class);

        if (null !== $state) {
            $builder->andWhere('review.state = :state')->setParameter('state', $state->value);
        }

        return $builder->getQuery()->getResult();
    }
}
