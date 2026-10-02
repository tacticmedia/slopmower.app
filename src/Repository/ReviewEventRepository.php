<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReviewEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReviewEvent>
 */
final class ReviewEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReviewEvent::class);
    }
}
