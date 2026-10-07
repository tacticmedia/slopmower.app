<?php

declare(strict_types=1);

namespace App\Domain\Event;

final readonly class RequestPlanWaivedEvent implements DomainEvent
{
    public static function type(): EventType
    {
        return EventType::RequestPlanWaived;
    }

    public function toPayload(): array
    {
        return [];
    }

    public static function fromPayload(array $payload): self
    {
        return new self();
    }
}
