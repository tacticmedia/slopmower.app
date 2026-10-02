<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GitHubCandidate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GitHubCandidate>
 */
final class GitHubCandidateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GitHubCandidate::class);
    }

    public function findByGitHubId(int $githubRepositoryId): ?GitHubCandidate
    {
        return $this->findOneBy(['githubRepositoryId' => $githubRepositoryId]);
    }

    /**
     * R2: sorted by the full name case-insensitively.
     *
     * @param string|null $q                     Case-insensitive substring of the full name
     * @param list<int>   $excludedRepositoryIds
     *
     * @return list<GitHubCandidate>
     */
    public function search(?string $q, array $excludedRepositoryIds, int $limit): array
    {
        $builder = $this->createQueryBuilder('candidate')
            ->addSelect("LOWER(CONCAT(candidate.owner, '/', candidate.name)) AS HIDDEN sortName")
            ->orderBy('sortName')
            ->addOrderBy('candidate.githubRepositoryId')
            ->setMaxResults($limit);

        if (null !== $q) {
            $builder
                ->andWhere("LOCATE(LOWER(:q), LOWER(CONCAT(candidate.owner, '/', candidate.name))) > 0")
                ->setParameter('q', $q);
        }

        if ([] !== $excludedRepositoryIds) {
            $builder
                ->andWhere('candidate.githubRepositoryId NOT IN (:excluded)')
                ->setParameter('excluded', $excludedRepositoryIds);
        }

        /* @var list<GitHubCandidate> */
        return $builder->getQuery()->getResult();
    }

    /**
     * `githubListedAt` of R2. Null without candidates.
     */
    public function lastListedAt(): ?\DateTimeImmutable
    {
        return $this->findOneBy([], ['listedAt' => 'DESC'])?->getListedAt();
    }

    /**
     * The SQL filter limits the delete to the tenant.
     */
    public function deleteAll(): void
    {
        $this->createQueryBuilder('candidate')->delete()->getQuery()->execute();
    }
}
