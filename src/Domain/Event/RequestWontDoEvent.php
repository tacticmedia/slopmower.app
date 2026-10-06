<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Codec\PayloadReader;

final readonly class RequestWontDoEvent implements DomainEvent
{
    public function __construct(public WontDoReason $reason)
    {
    }

    public static function type(): EventType
    {
        return EventType::RequestWontDo;
    }

    public function toPayload(): array
    {
        return ['reason' => $this->reason->toString()];
    }

    public static function fromPayload(array $payload): self
    {
        $reader = PayloadReader::of($payload);

        return new self($reader->build(static fn (): WontDoReason => WontDoReason::fromString($reader->string('reason'))));
    }
}
