<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Event\RecordedEvent;
use App\Domain\Identity\RequestId;
use App\Domain\Request\RequestStatus;

/**
 * thread-entry.schema.json: one event of the thread of a request.
 */
final readonly class ThreadEntry
{
    /**
     * @param RequestStatus $statusAfter The status of the request of the thread after the event
     */
    public function __construct(
        public RequestId $requestId,
        public RecordedEvent $event,
        public RequestStatus $statusAfter,
    ) {
    }

    /**
     * A commit.linked or commit.found event concerns several requests, so its Event.statusAfter is null.
     */
    public function eventView(): EventView
    {
        $subject = $this->event->subjectRequestId();

        return new EventView($this->event, null !== $subject && $subject->equals($this->requestId) ? $this->statusAfter : null);
    }
}
