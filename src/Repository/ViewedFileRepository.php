<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Git\RepoPath;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\UserId;
use App\Entity\ViewedFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<ViewedFile>
 */
final class ViewedFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ViewedFile::class);
    }

    /**
     * @return list<string> Sorted
     */
    public function paths(UserId $userId, ReviewId $reviewId): array
    {
        /** @var list<array{path: RepoPath}> $rows */
        $rows = $this->createQueryBuilder('viewed')
            ->select('viewed.path')
            ->where('viewed.user = :userId')
            ->andWhere('viewed.review = :reviewId')
            ->orderBy('viewed.path', 'ASC')
            ->setParameter('userId', Ulid::fromString($userId->toString()), UlidType::NAME)
            ->setParameter('reviewId', Ulid::fromString($reviewId->toString()), UlidType::NAME)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): string => $row['path']->toString(), $rows);
    }
}
