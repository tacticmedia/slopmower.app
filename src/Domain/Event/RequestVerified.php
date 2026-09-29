<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Codec\PayloadReader;

final readonly class RequestVerified implements DomainEvent
{
    public function __construct(
        public ?string $note,
    ) {
    }

    public static function type(): EventType
    {
        return EventType::RequestVerified;
    }

    /**
     * @return array{note: string|null}
     */
    public function toPayload(): array
    {
        return ['note' => $this->note];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(PayloadReader::of($payload)->nullableString('note'));
    }
}
