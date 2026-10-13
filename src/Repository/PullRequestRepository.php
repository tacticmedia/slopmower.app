<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Git\CommitSha;
use App\Domain\Identity\PullRequestId;
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

    public function findFresh(PullRequestId $id): ?PullRequest
    {
        return $this->createQueryBuilder('pullRequest')
            ->where('pullRequest.id = :id')
            ->setParameter('id', $id, PullRequestId::class)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * Sets the flag when no check run job is queued yet.
     *
     * @return bool Whether a job must be queued
     */
    public function requestCheckRun(PullRequestId $id): bool
    {
        return 1 === $this->update($id)
            ->set('pullRequest.checkRunRequested', 'true')
            ->andWhere('pullRequest.checkRunRequested = false')
            ->getQuery()
            ->execute();
    }

    public function clearCheckRunRequest(PullRequestId $id): void
    {
        $this->update($id)->set('pullRequest.checkRunRequested', 'false')->getQuery()->execute();
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
     * A check run belongs to a commit, so an open record on the head counts as well as a record of the number.
     */
    public function hasNumberOrOpenHead(RepositoryId $repositoryId, ?int $number, CommitSha $headSha): bool
    {
        $builder = $this->ofRepository($repositoryId)->select('COUNT(pullRequest.id)');
        $openHead = $builder->expr()->andX('pullRequest.state = :open', 'pullRequest.headSha = :headSha');
        $builder->andWhere(null === $number ? $openHead : $builder->expr()->orX($openHead, 'pullRequest.number = :number'))
            ->setParameter('open', 'open')
            ->setParameter('headSha', $headSha, CommitSha::class);

        if (null !== $number) {
            $builder->setParameter('number', $number);
        }

        return 0 < (int) $builder->getQuery()->getSingleScalarResult();
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

    private function update(PullRequestId $id): QueryBuilder
    {
        return $this->createQueryBuilder('pullRequest')
            ->update()
            ->where('pullRequest.id = :id')
            ->setParameter('id', $id, PullRequestId::class);
    }

    private function ofRepository(RepositoryId $repositoryId): QueryBuilder
    {
        return $this->createQueryBuilder('pullRequest')
            ->where('pullRequest.repository = :repository')
            ->setParameter('repository', $repositoryId, RepositoryId::class);
    }
}
