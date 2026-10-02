<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Codec\PayloadReader;

final readonly class RequestVerifiedEvent implements DomainEvent
{
    public function __construct(public ?string $note)
    {
    }

    public static function type(): EventType
    {
        return EventType::RequestVerified;
    }

    public function toPayload(): array
    {
        return ['note' => $this->note];
    }

    public static function fromPayload(array $payload): self
    {
        return new self(PayloadReader::of($payload)->nullableString('note'));
    }
}
