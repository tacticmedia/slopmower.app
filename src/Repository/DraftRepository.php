<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Identity\ReviewId;
use App\Domain\Identity\UserId;
use App\Entity\Draft;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<Draft>
 */
final class DraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Draft::class);
    }

    /**
     * Sorted by createdAt. The ID orders drafts of the same second. Refreshes managed instances.
     *
     * @return list<Draft>
     */
    public function findOfUser(UserId $userId, ReviewId $reviewId): array
    {
        /** @var list<Draft> $drafts */
        $drafts = $this->ofUser($userId, $reviewId)
            ->orderBy('draft.createdAt', 'ASC')
            ->addOrderBy('draft.id', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $drafts;
    }

    public function countOfUser(UserId $userId, ReviewId $reviewId): int
    {
        return (int) $this->ofUser($userId, $reviewId)->select('COUNT(draft.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * A bulk delete: it bypasses the identity map.
     */
    public function deleteOfUser(UserId $userId, ReviewId $reviewId): void
    {
        $this->ofUser($userId, $reviewId)->delete()->getQuery()->execute();
    }

    private function ofUser(UserId $userId, ReviewId $reviewId): QueryBuilder
    {
        return $this->createQueryBuilder('draft')
            ->where('draft.user = :userId')
            ->andWhere('draft.review = :reviewId')
            ->setParameter('userId', Ulid::fromString($userId->toString()), UlidType::NAME)
            ->setParameter('reviewId', Ulid::fromString($reviewId->toString()), UlidType::NAME);
    }
}
