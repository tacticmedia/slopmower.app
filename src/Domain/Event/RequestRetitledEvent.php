<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Codec\PayloadReader;

final readonly class RequestRetitledEvent implements DomainEvent
{
    public function __construct(public string $title)
    {
    }

    public static function type(): EventType
    {
        return EventType::RequestRetitled;
    }

    public function toPayload(): array
    {
        return ['title' => $this->title];
    }

    public static function fromPayload(array $payload): self
    {
        return new self(PayloadReader::of($payload)->string('title'));
    }
}
