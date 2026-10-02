<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Actor\Actor;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;

/**
 * One `review_event` row.
 */
final readonly class RecordedEvent
{
    public EventType $type;

    public function __construct(
        public EventId $id,
        public TenantId $tenantId,
        public int $seq,
        public ReviewId $reviewId,
        public ?RequestId $requestId,
        public Actor $actor,
        public \DateTimeImmutable $occurredAt,
        public DomainEvent $event,
    ) {
        $this->type = $event::type();

        if ($this->type->isRequestEvent() !== (null !== $requestId)) {
            throw new \LogicException($this->type->isRequestEvent() ? "{$this->type->value} needs a requestId." : "{$this->type->value} has no requestId.");
        }
    }
}
