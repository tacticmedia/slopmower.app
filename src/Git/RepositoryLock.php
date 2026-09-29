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
    public function __construct(
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * The key `fetch:<repositoryId>` of docs/specs section 7. The lock has no TTL, because advisory locks never expire.
     * It is released by release() or when the lock object is destroyed.
     */
    public function create(RepositoryId $repositoryId): LockInterface
    {
        return $this->lockFactory->createLock('fetch:'.$repositoryId->toString(), null);
    }
}
