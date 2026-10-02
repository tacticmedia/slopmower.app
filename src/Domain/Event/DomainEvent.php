<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Codec\CorruptPayload;

/**
 * The payload of one `review_event` row. {@see RecordedEvent} holds the other columns.
 */
interface DomainEvent
{
    public static function type(): EventType;

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array;

    /**
     * @param array<mixed> $payload
     *
     * @throws CorruptPayload
     */
    public static function fromPayload(array $payload): self;
}
