<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\RequestReducer;
use App\Repository\ReviewEventRepository;

/**
 * The thread of a request with the status after each entry, folded as the projector folds it.
 */
final class ThreadQuery
{
    public function __construct(private readonly ReviewEventRepository $events)
    {
    }

    /**
     * @return list<ThreadEntry> In seq order, starting with request.opened
     */
    public function entries(ReviewId $reviewId, RequestId $requestId, ?int $upToSeq = null): array
    {
        $state = null;
        $entries = [];

        foreach ($this->events->findThread($reviewId, $requestId, $upToSeq) as $event) {
            $recorded = $event->toRecordedEvent();
            $state = RequestReducer::apply($state, $recorded);
            $entries[] = new ThreadEntry($requestId, $recorded, $state->status);
        }

        return $entries;
    }

    /**
     * @return array<int, ThreadEntry> By seq
     */
    public function entriesBySeq(ReviewId $reviewId, RequestId $requestId, ?int $upToSeq = null): array
    {
        $bySeq = [];

        foreach ($this->entries($reviewId, $requestId, $upToSeq) as $entry) {
            $bySeq[$entry->event->seq] = $entry;
        }

        return $bySeq;
    }
}
