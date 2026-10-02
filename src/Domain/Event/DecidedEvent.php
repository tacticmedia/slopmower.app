<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Identity\RequestId;

/**
 * A decider result before the event store numbers it. {@see RecordedEvent} adds the other columns.
 */
final readonly class DecidedEvent
{
    public function __construct(
        public DomainEvent $event,
        public ?RequestId $requestId = null,
    ) {
        $type = $event::type();

        if ($type->isRequestEvent() !== (null !== $requestId)) {
            throw new \LogicException($type->isRequestEvent() ? "{$type->value} needs a requestId." : "{$type->value} has no requestId.");
        }
    }
}
