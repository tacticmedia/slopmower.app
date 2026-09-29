<?php

declare(strict_types=1);

namespace App\Domain\Event\Codec;

use App\Domain\Error\InvalidInput;
use App\Domain\Event\BatchSubmitted;
use App\Domain\Event\CommitFound;
use App\Domain\Event\CommitLinked;
use App\Domain\Event\CommitUnlinked;
use App\Domain\Event\DomainEvent;
use App\Domain\Event\EventType;
use App\Domain\Event\RequestClaimed;
use App\Domain\Event\RequestCommented;
use App\Domain\Event\RequestOpened;
use App\Domain\Event\RequestReadyForReview;
use App\Domain\Event\RequestReleased;
use App\Domain\Event\RequestReopened;
use App\Domain\Event\RequestVerified;
use App\Domain\Event\RequestWontDo;
use App\Domain\Event\ReviewClosed;
use App\Domain\Event\ReviewStarted;

/**
 * Decision D37: explicit codecs per event type, no Serializer, no reflection.
 * Stored events are never rewritten, so their payload format must not drift.
 */
final class EventCodec
{
    /**
     * @return class-string<DomainEvent>
     */
    public static function eventClass(EventType $type): string
    {
        return match ($type) {
            EventType::ReviewStarted => ReviewStarted::class,
            EventType::ReviewClosed => ReviewClosed::class,
            EventType::RequestOpened => RequestOpened::class,
            EventType::RequestCommented => RequestCommented::class,
            EventType::RequestClaimed => RequestClaimed::class,
            EventType::RequestReleased => RequestReleased::class,
            EventType::CommitLinked => CommitLinked::class,
            EventType::CommitUnlinked => CommitUnlinked::class,
            EventType::CommitFound => CommitFound::class,
            EventType::RequestReadyForReview => RequestReadyForReview::class,
            EventType::RequestWontDo => RequestWontDo::class,
            EventType::RequestVerified => RequestVerified::class,
            EventType::RequestReopened => RequestReopened::class,
            EventType::BatchSubmitted => BatchSubmitted::class,
        };
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromPayload(string $type, array $payload): DomainEvent
    {
        return self::eventClass(self::eventType($type))::fromPayload($payload);
    }

    public static function decode(string $type, string $payloadJson): DomainEvent
    {
        $eventClass = self::eventClass(self::eventType($type));

        return $eventClass::fromPayload(PayloadJson::decode($payloadJson));
    }

    public static function encode(DomainEvent $event): string
    {
        return PayloadJson::encode($event->toPayload());
    }

    private static function eventType(string $type): EventType
    {
        return EventType::tryFrom($type) ?? throw InvalidInput::field('type', 'must be a known event type');
    }
}
