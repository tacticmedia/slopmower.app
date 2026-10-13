<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Identity\RepositoryId;
use App\Entity\PullRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PullRequest>
 */
final class PullRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PullRequest::class);
    }

    /**
     * @param list<int> $numbers
     *
     * @return array<int, PullRequest> By number
     */
    public function findOfRepository(RepositoryId $repositoryId, array $numbers): array
    {
        if ([] === $numbers) {
            return [];
        }

        /** @var list<PullRequest> $rows */
        $rows = $this->ofRepository($repositoryId)
            ->andWhere('pullRequest.number IN (:numbers)')
            ->setParameter('numbers', $numbers, ArrayParameterType::INTEGER)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        $byNumber = [];

        foreach ($rows as $row) {
            $byNumber[$row->getNumber()] = $row;
        }

        return $byNumber;
    }

    /**
     * Inside a transaction: locks the row until the commit.
     */
    public function findForUpdate(RepositoryId $repositoryId, int $number): ?PullRequest
    {
        return $this->ofRepository($repositoryId)
            ->andWhere('pullRequest.number = :number')
            ->setParameter('number', $number)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * Across tenants when the tenant filter is suspended.
     *
     * @return list<PullRequest>
     */
    public function findByGitHubRepository(int $githubRepositoryId, int $number): array
    {
        return $this->createQueryBuilder('pullRequest')
            ->join('pullRequest.repository', 'repository')
            ->where('repository.githubRepositoryId = :githubRepositoryId')
            ->andWhere('pullRequest.number = :number')
            ->setParameter('githubRepositoryId', $githubRepositoryId)
            ->setParameter('number', $number)
            ->getQuery()
            ->getResult();
    }

    private function ofRepository(RepositoryId $repositoryId): QueryBuilder
    {
        return $this->createQueryBuilder('pullRequest')
            ->where('pullRequest.repository = :repository')
            ->setParameter('repository', $repositoryId, RepositoryId::class);
    }
}
