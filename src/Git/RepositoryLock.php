<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Identity\RepositoryId;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Fetch, clone, and clone deletion hold it. Pin writes and reads do not: they take {@see withPinLock()}.
 */
final class RepositoryLock
{
    private const float POLL_SECONDS = 0.1;

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * No TTL: PostgreSQL advisory locks do not expire and end with the database session.
     */
    public function create(RepositoryId $repositoryId): LockInterface
    {
        return $this->lockFactory->createLock("fetch:{$repositoryId}", ttl: null);
    }

    /**
     * Polls, because Symfony Lock has no acquire timeout and the PostgreSQL store blocks without limit.
     *
     * @param float|null $deadlineUnixSeconds Null for no limit
     *
     * @return ($deadlineUnixSeconds is null ? LockInterface : LockInterface|null) Null when the deadline passes first
     */
    public function acquire(RepositoryId $repositoryId, ?float $deadlineUnixSeconds = null): ?LockInterface
    {
        $lock = $this->create($repositoryId);

        while (!$lock->acquire()) {
            if (null !== $deadlineUnixSeconds && (float) $this->clock->now()->format('U.u') >= $deadlineUnixSeconds) {
                return null;
            }

            $this->clock->sleep(self::POLL_SECONDS);
        }

        return $lock;
    }

    /**
     * D91: a pin with the transaction that links its commit, and the link check with the unpin. Taken before the
     * tenant row lock, never inside it.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function withPinLock(RepositoryId $repositoryId, \Closure $work): mixed
    {
        $lock = $this->lockFactory->createLock("pins:{$repositoryId}", ttl: null);
        $lock->acquire(true);

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
