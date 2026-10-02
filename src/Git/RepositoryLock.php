<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Identity\RepositoryId;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Fetch, clone, and clone deletion hold it. Pin writes and reads do not.
 */
final class RepositoryLock
{
    public function __construct(private readonly LockFactory $lockFactory)
    {
    }

    /**
     * No TTL: PostgreSQL advisory locks do not expire and end with the database session.
     */
    public function create(RepositoryId $repositoryId): LockInterface
    {
        return $this->lockFactory->createLock("fetch:{$repositoryId}", ttl: null);
    }
}
