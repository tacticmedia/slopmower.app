<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Error\InvalidInput;

/**
 * The payload of one event type. {@see Codec\EventCodec} maps each type to its class.
 */
interface DomainEvent
{
    public static function type(): EventType;

    /**
     * @return array<string, mixed> JSON-ready, see {@see Codec\PayloadJson::encode()}
     */
    public function toPayload(): array;

    /**
     * @param array<mixed> $payload decoded JSON, read through {@see Codec\PayloadReader}
     *
     * @throws InvalidInput naming the dotted field under `payload`
     */
    public static function fromPayload(array $payload): static;
}
