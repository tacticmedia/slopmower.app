<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GitHubInstallation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GitHubInstallation>
 */
final class GitHubInstallationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GitHubInstallation::class);
    }

    public function isSuspended(int $installationId): bool
    {
        return true === $this->findOneBy(['installationId' => $installationId])?->isSuspended();
    }

    /**
     * The SQL filter limits the delete to the tenant.
     */
    public function deleteAll(): void
    {
        $this->createQueryBuilder('installation')->delete()->getQuery()->execute();
    }
}
