<?php

declare(strict_types=1);

namespace App\Domain\Event;

final readonly class RequestClaimed implements DomainEvent
{
    public static function type(): EventType
    {
        return EventType::RequestClaimed;
    }

    /**
     * @return array{}
     */
    public function toPayload(): array
    {
        return [];
    }

    public static function fromPayload(array $payload): static
    {
        return new self();
    }
}
