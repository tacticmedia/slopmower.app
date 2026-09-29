<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event\Codec;

use App\Domain\Event\Codec\EventCodec;
use App\Domain\Event\DomainEvent;
use App\Domain\Event\EventType;
use App\Domain\Event\RequestClaimed;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EventCodecTest extends KernelTestCase
{
    use ViolatedFields;

    #[TestDox('Maps an event type to the class of that type')]
    public function testMapsAnEventTypeToTheClassOfThatType(): void
    {
        $eventClassesByType = [];
        foreach (EventType::cases() as $type) {
            $eventClass = EventCodec::eventClass($type);
            self::assertSame($type, $eventClass::type());
            $eventClassesByType[$type->value] = $eventClass;
        }

        self::assertSame(RequestClaimed::class, $eventClassesByType['request.claimed']);
        self::assertCount(\count(EventType::cases()), array_unique($eventClassesByType));
    }

    #[TestDox('Decodes a stored payload by its type string and ignores unknown fields')]
    public function testDecodesAStoredPayloadByItsTypeString(): void
    {
        self::assertInstanceOf(RequestClaimed::class, EventCodec::decode('request.claimed', '{}'));
        self::assertInstanceOf(RequestClaimed::class, EventCodec::decode('request.claimed', '{"addedInALaterRelease":1}'));
        self::assertInstanceOf(RequestClaimed::class, EventCodec::fromPayload('request.claimed', []));
    }

    #[TestDox('Encodes the payload of an event as compact JSON')]
    public function testEncodesThePayloadOfAnEventAsCompactJson(): void
    {
        self::assertSame('{}', EventCodec::encode(new RequestClaimed()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownTypes(): iterable
    {
        yield 'empty' => [''];
        yield 'not an event type' => ['request.deleted'];
        yield 'upper case' => ['REQUEST.CLAIMED'];
        yield 'thread entry form' => ['request_claimed'];
    }

    #[DataProvider('unknownTypes')]
    #[TestDox('Rejects an unknown event type before the payload, naming the type field')]
    public function testRejectsAnUnknownEventType(string $type): void
    {
        self::assertSame(['type'], self::violatedFields(static fn (): DomainEvent => EventCodec::decode($type, 'not JSON')));
        self::assertSame(['type'], self::violatedFields(static fn (): DomainEvent => EventCodec::fromPayload($type, [])));
    }

    #[TestDox('Rejects a stored payload that is not a JSON object, naming the payload field')]
    public function testRejectsAStoredPayloadThatIsNotAJsonObject(): void
    {
        self::assertSame(['payload'], self::violatedFields(static fn (): DomainEvent => EventCodec::decode('request.claimed', '[]')));
    }
}
