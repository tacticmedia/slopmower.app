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
        return $this->entriesOfRequests($reviewId, [$requestId], $upToSeq)[$requestId->toString()];
    }

    /**
     * One query for all requests.
     *
     * @param list<RequestId> $requestIds
     *
     * @return array<string, list<ThreadEntry>> The {@see entries()} by request ID
     */
    public function entriesOfRequests(ReviewId $reviewId, array $requestIds, ?int $upToSeq = null): array
    {
        $entries = array_fill_keys(array_map(strval(...), $requestIds), []);
        $states = [];

        foreach ($this->events->findThreads($reviewId, $requestIds, $upToSeq) as $event) {
            $recorded = $event->toRecordedEvent();

            foreach ($recorded->requestIds() as $requestId) {
                $key = $requestId->toString();

                if (isset($entries[$key])) {
                    $states[$key] = RequestReducer::apply($states[$key] ?? null, $recorded);
                    $entries[$key][] = new ThreadEntry($requestId, $recorded, $states[$key]->status);
                }
            }
        }

        return $entries;
    }

    /**
     * @return array<int, ThreadEntry> By seq
     */
    public function entriesBySeq(ReviewId $reviewId, RequestId $requestId, ?int $upToSeq = null): array
    {
        return self::bySeq($this->entries($reviewId, $requestId, $upToSeq));
    }

    /**
     * @param list<ThreadEntry> $entries
     *
     * @return array<int, ThreadEntry>
     */
    public static function bySeq(array $entries): array
    {
        return array_combine(array_map(static fn (ThreadEntry $entry): int => $entry->event->seq, $entries), $entries);
    }
}
