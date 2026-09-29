<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\Violation;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;

/**
 * One row of `review_event`.
 */
final readonly class RecordedEvent
{
    public function __construct(
        public EventId $id,
        public TenantId $tenantId,
        public int $seq,
        public ReviewId $reviewId,
        public ?RequestId $requestId,
        public EventType $type,
        public Actor $actor,
        public \DateTimeImmutable $occurredAt,
        public DomainEvent $event,
    ) {
        $violations = [];
        if ($seq < 1) {
            $violations[] = new Violation('seq', 'must be 1 or more');
        }
        if ($event::type() !== $type) {
            $violations[] = new Violation('type', \sprintf('must be %s, the type of the event', $event::type()->value));
        }
        if ($type->hasRequestId() !== (null !== $requestId)) {
            $violations[] = new Violation('requestId', $type->hasRequestId() ? 'is required for a request event' : \sprintf('must be null for a %s event', $type->value));
        }
        if (EventType::CommitFound === $type && ActorKind::System !== $actor->kind) {
            $violations[] = new Violation('actor', 'must be the system for a commit.found event');
        }
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }
    }
}
