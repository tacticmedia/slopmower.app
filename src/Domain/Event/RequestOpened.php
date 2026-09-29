<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Anchor\Anchor;
use App\Domain\Event\Codec\AnchorCodec;
use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Identity\BatchId;

/**
 * @phpstan-import-type EncodedAnchor from AnchorCodec
 */
final readonly class RequestOpened implements DomainEvent
{
    public function __construct(
        public Anchor $anchor,
        public string $body,
        public ?BatchId $batchId,
    ) {
    }

    public static function type(): EventType
    {
        return EventType::RequestOpened;
    }

    /**
     * @return array{anchor: EncodedAnchor, body: string, batchId: string|null}
     */
    public function toPayload(): array
    {
        return [
            'anchor' => AnchorCodec::encode($this->anchor),
            'body' => $this->body,
            'batchId' => $this->batchId?->toString(),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        $reader = PayloadReader::of($payload);

        return new self(
            AnchorCodec::decode($reader->object('anchor')),
            $reader->string('body'),
            $reader->nullableId('batchId', BatchId::class),
        );
    }
}
