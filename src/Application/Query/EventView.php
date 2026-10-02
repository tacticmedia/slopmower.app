<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Event\RecordedEvent;
use App\Domain\Request\RequestStatus;

/**
 * event.schema.json.
 */
final readonly class EventView
{
    /**
     * @param RequestStatus|null $statusAfter The status of {@see RecordedEvent::subjectRequestId()} after the event
     */
    public function __construct(
        public RecordedEvent $event,
        public ?RequestStatus $statusAfter,
    ) {
    }
}
