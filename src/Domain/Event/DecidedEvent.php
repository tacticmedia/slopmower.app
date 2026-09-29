<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Identity\RequestId;

/**
 * An event that a decider returns. The command service wraps it into a {@see RecordedEvent}.
 */
final readonly class DecidedEvent
{
    private function __construct(
        public ?RequestId $requestId,
        public DomainEvent $event,
    ) {
    }

    /**
     * @throws \LogicException for an event type without a requestId in the envelope
     */
    public static function forRequest(RequestId $requestId, DomainEvent $event): self
    {
        if (!$event::type()->hasRequestId()) {
            throw new \LogicException(\sprintf('A %s event has no requestId in the envelope.', $event::type()->value));
        }

        return new self($requestId, $event);
    }

    /**
     * @throws \LogicException for a request event
     */
    public static function withoutRequest(DomainEvent $event): self
    {
        if ($event::type()->hasRequestId()) {
            throw new \LogicException(\sprintf('A %s event needs a requestId in the envelope.', $event::type()->value));
        }

        return new self(null, $event);
    }

    public function type(): EventType
    {
        return $this->event::type();
    }
}
