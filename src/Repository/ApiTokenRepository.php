<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Identity\UserId;
use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
final class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    /**
     * The rule of {@see ApiToken::state()} in DQL.
     */
    public function countActive(User $user, \DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('token')
            ->select('COUNT(token.id)')
            ->where('token.user = :userId')
            ->andWhere('token.revokedAt IS NULL')
            ->andWhere('token.expiresAt IS NULL OR token.expiresAt > :now')
            ->setParameter('userId', $user->getId(), UserId::class)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
