<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CommitLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommitLink>
 */
final class CommitLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommitLink::class);
    }
}
