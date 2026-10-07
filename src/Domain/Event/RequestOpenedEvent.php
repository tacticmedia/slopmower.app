<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Anchor\Anchor;
use App\Domain\Event\Codec\AnchorCodec;
use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\CarriedFrom;

final readonly class RequestOpenedEvent implements DomainEvent
{
    public function __construct(
        public ?Anchor $anchor,
        public string $body,
        public ?BatchId $batchId,
        public ?CarriedFrom $carriedFrom = null,
    ) {
    }

    public static function type(): EventType
    {
        return EventType::RequestOpened;
    }

    /**
     * The key order of the example in architecture section 3.
     */
    public function toPayload(): array
    {
        return [
            'batchId' => $this->batchId?->toString(),
            'body' => $this->body,
            'anchor' => null === $this->anchor ? null : AnchorCodec::encode($this->anchor),
            'carriedFrom' => null === $this->carriedFrom ? null : [
                'reviewId' => $this->carriedFrom->reviewId->toString(),
                'requestId' => $this->carriedFrom->requestId->toString(),
            ],
        ];
    }

    public static function fromPayload(array $payload): self
    {
        $reader = PayloadReader::of($payload);
        $anchor = $reader->nullableObject('anchor');
        $carriedFrom = $reader->nullableObject('carriedFrom');

        return new self(
            null === $anchor ? null : AnchorCodec::decode($anchor),
            $reader->string('body'),
            $reader->nullableId('batchId', BatchId::class),
            null === $carriedFrom ? null : new CarriedFrom($carriedFrom->id('reviewId', ReviewId::class), $carriedFrom->id('requestId', RequestId::class)),
        );
    }
}
