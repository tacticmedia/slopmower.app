<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Identity\ReviewId;
use App\Domain\Identity\UserId;
use Symfony\Component\Lock\LockFactory;

/**
 * The drafts of one user in one review: the count and insert of a create, an edit, a delete, a submit, and the close
 * that deletes them. Taken before the tenant row lock.
 */
final class DraftLock
{
    public function __construct(private readonly LockFactory $lockFactory)
    {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function run(ReviewId $reviewId, UserId $userId, \Closure $work): mixed
    {
        $lock = $this->lockFactory->createLock("drafts:{$reviewId}:{$userId}", ttl: null);
        $lock->acquire(true);

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
