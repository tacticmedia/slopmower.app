<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\Type\CommitShaType;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RepositoryId;
use App\Domain\Repository\RepositorySource;
use App\Domain\Repository\SyncState;
use App\Entity\Repository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The sync state updates are atomic and conditional, because the php and worker processes change one row concurrently.
 * They bypass the identity map.
 *
 * @extends ServiceEntityRepository<Repository>
 */
final class RepositoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Repository::class);
    }

    /**
     * Overwrites a managed instance with the stored row.
     */
    public function findFresh(RepositoryId $id): ?Repository
    {
        return $this->createQueryBuilder('repository')
            ->where('repository.id = :id')
            ->setParameter('id', Ulid::fromString($id->toString()), UlidType::NAME)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    public function findLocal(string $directory): ?Repository
    {
        return $this->findOneBy(['source' => RepositorySource::Local, 'name' => $directory]);
    }

    /**
     * Sets the flag and `fetching`, unless a fetch job is queued already.
     *
     * @return bool Whether a job must be queued
     */
    public function requestFetch(RepositoryId $id): bool
    {
        return 1 === $this->update($id)
            ->set('repository.fetchRequested', 'true')
            ->set('repository.syncState', ':fetching')
            ->andWhere('repository.fetchRequested = false')
            ->andWhere('repository.syncState IN (:fetchable)')
            ->setParameter('fetching', SyncState::Fetching)
            ->setParameter('fetchable', [SyncState::Idle, SyncState::Fetching])
            ->getQuery()
            ->execute();
    }

    public function clearFetchRequest(RepositoryId $id): void
    {
        $this->update($id)->set('repository.fetchRequested', 'false')->getQuery()->execute();
    }

    /**
     * @return bool False when the state is not `idle` or `fetching`
     */
    public function startFetch(RepositoryId $id): bool
    {
        return 1 === $this->update($id)
            ->set('repository.syncState', ':fetching')
            ->andWhere('repository.syncState IN (:fetchable)')
            ->setParameter('fetching', SyncState::Fetching)
            ->setParameter('fetchable', [SyncState::Idle, SyncState::Fetching])
            ->getQuery()
            ->execute();
    }

    /**
     * @return bool False when the state is not `failed`
     */
    public function restartClone(RepositoryId $id): bool
    {
        return 1 === $this->update($id)
            ->set('repository.syncState', ':cloning')
            ->andWhere('repository.syncState = :failed')
            ->setParameter('cloning', SyncState::Cloning)
            ->setParameter('failed', SyncState::Failed)
            ->getQuery()
            ->execute();
    }

    /**
     * Ends a clone or fetch with `idle`, or with `fetching` while a fetch job is queued. Other states stay.
     */
    public function recordSuccess(RepositoryId $id, \DateTimeImmutable $fetchedAt, ?string $defaultBranch, ?CommitSha $defaultBranchHead): void
    {
        $this->running($id)
            ->set('repository.syncState', 'CASE WHEN repository.fetchRequested = true THEN :fetching ELSE :idle END')
            ->set('repository.lastFetchedAt', ':fetchedAt')
            ->set('repository.lastError', 'NULL')
            ->set('repository.defaultBranch', ':defaultBranch')
            ->set('repository.defaultBranchHead', ':defaultBranchHead')
            ->setParameter('fetchedAt', $fetchedAt, Types::DATETIME_IMMUTABLE)
            ->setParameter('defaultBranch', $defaultBranch)
            ->setParameter('defaultBranchHead', $defaultBranchHead, CommitShaType::NAME)
            ->getQuery()
            ->execute();
    }

    /**
     * Ends a clone with `failed`, and a fetch as {@see recordSuccess()} does. Other states stay.
     */
    public function recordFailure(RepositoryId $id, string $error): void
    {
        $this->running($id)
            ->set('repository.syncState', 'CASE WHEN repository.syncState = :cloning THEN :failed WHEN repository.fetchRequested = true THEN :fetching ELSE :idle END')
            ->set('repository.lastError', ':error')
            ->setParameter('cloning', SyncState::Cloning)
            ->setParameter('failed', SyncState::Failed)
            ->setParameter('error', $error)
            ->getQuery()
            ->execute();
    }

    private function update(RepositoryId $id): QueryBuilder
    {
        return $this->createQueryBuilder('repository')
            ->update()
            ->where('repository.id = :id')
            ->setParameter('id', Ulid::fromString($id->toString()), UlidType::NAME);
    }

    private function running(RepositoryId $id): QueryBuilder
    {
        return $this->update($id)
            ->andWhere('repository.syncState IN (:running)')
            ->setParameter('running', [SyncState::Cloning, SyncState::Fetching])
            ->setParameter('fetching', SyncState::Fetching)
            ->setParameter('idle', SyncState::Idle);
    }
}
