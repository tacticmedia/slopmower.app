<?php

declare(strict_types=1);

namespace App\EventStore;

use App\Domain\Event\RecordedEvent;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;

/**
 * Dispatched by {@see CommandExecutor} after the commit, so a listener can dispatch Messenger messages.
 */
final readonly class EventsCommitted
{
    public int $lastSeq;

    /** @var list<RequestId> Every request that an event of the transaction concerns, in event order */
    public array $requestIds;

    /**
     * @param non-empty-list<RecordedEvent> $events
     */
    public function __construct(
        public TenantId $tenantId,
        public ReviewId $reviewId,
        public array $events,
    ) {
        $this->lastSeq = array_last($events)->seq;
        $requestIds = [];

        foreach ($events as $event) {
            foreach ($event->requestIds() as $requestId) {
                $requestIds[$requestId->toString()] ??= $requestId;
            }
        }

        $this->requestIds = array_values($requestIds);
    }
}
