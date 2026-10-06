<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Identity\TenantId;
use App\Entity\Comment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Comment>
 */
final class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * A bulk delete: it bypasses the identity map.
     */
    public function deleteOfTenant(TenantId $tenantId): void
    {
        $this->createQueryBuilder('comment')
            ->delete()
            ->where('comment.tenantId = :tenantId')
            ->setParameter('tenantId', $tenantId, TenantId::class)
            ->getQuery()
            ->execute();
    }
}
