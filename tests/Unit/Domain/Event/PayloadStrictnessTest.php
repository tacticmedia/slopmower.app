<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Error\InvalidInput;
use App\Domain\Event\Codec\ActorCodec;
use App\Domain\Event\Codec\EventCodec;
use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Event\EventType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PayloadStrictnessTest extends KernelTestCase
{
    private const string UNKNOWN_FIELD = 'addedInALaterRelease';

    /**
     * The fixture keys that may be missing. The commit.linked fixture is available, so it requires subject and committedAt.
     */
    private const array NULLABLE_KEYS = [
        'review.closed' => ['note'],
        'request.opened' => ['batchId'],
        'request.commented' => ['batchId'],
        'request.released' => ['note'],
        'commit.linked' => ['note'],
        'request.verified' => ['note'],
        'batch.submitted' => ['summary'],
    ];

    /**
     * One field per PayloadReader method. PayloadReaderTest covers the other wrong types.
     */
    private const array WRONG_TYPE_KEYS = [
        'review.started' => ['ref', 'commitSha'],
        'review.closed' => ['note'],
        'request.opened' => ['anchor', 'body', 'batchId'],
        'request.commented' => ['commentId'],
        'commit.linked' => ['available', 'committedAt'],
        'commit.found' => ['committedAt'],
    ];

    private const array NESTED_WRONG_TYPE_FIELDS = ['anchor.kind', 'anchor.symbol'];

    /**
     * Every top-level and nested key of every fixture, plus wrong types, enums, and the type.
     *
     * @return iterable<string, array{\Closure(): mixed, string, non-empty-string}>
     */
    public static function corruptValues(): iterable
    {
        foreach (EventType::cases() as $type) {
            $payload = EventFixtures::payload($type);
            foreach ($payload as $key => $value) {
                if (!\in_array($key, self::NULLABLE_KEYS[$type->value] ?? [], true)) {
                    yield $type->value.' without '.$key => [self::decode($type, array_diff_key($payload, [$key => true])), 'payload.'.$key, 'is required'];
                }
                if (\in_array($key, self::WRONG_TYPE_KEYS[$type->value] ?? [], true)) {
                    yield $type->value.' with '.$key.' of the wrong type' => [self::decode($type, [...$payload, $key => self::wrongType($value)]), 'payload.'.$key, 'must be a'];
                }
            }
        }

        $opened = EventFixtures::payload(EventType::RequestOpened);
        $linked = EventFixtures::payload(EventType::CommitLinked);
        $symbol = ['language' => 'php', 'kind' => 'macro', 'fqn' => 'App\Billing\InvoiceTotals::total()'];

        // A symbol anchor requires every nested field.
        $symbolOpened = array_replace_recursive($opened, ['anchor' => ['kind' => 'symbol', 'symbol' => [...$symbol, 'kind' => 'method']]]);
        foreach (self::nestedFields($symbolOpened) as [$keys, $value]) {
            $name = implode('.', $keys);
            yield 'request.opened on a symbol without '.$name => [self::decode(EventType::RequestOpened, self::without($symbolOpened, ...$keys)), 'payload.'.$name, 'is required'];
            if (\in_array($name, self::NESTED_WRONG_TYPE_FIELDS, true)) {
                yield 'request.opened on a symbol with '.$name.' of the wrong type' => [
                    self::decode(EventType::RequestOpened, self::with($symbolOpened, self::wrongType($value), ...$keys)),
                    'payload.'.$name,
                    'must be a',
                ];
            }
        }

        yield 'request.opened without anchor.range.endLine' => [
            self::decode(EventType::RequestOpened, self::without($opened, 'anchor', 'range', 'endLine')),
            'payload.anchor.range.endLine',
            'is required',
        ];
        yield 'request.opened without anchor.snapshot of a range anchor' => [
            self::decode(EventType::RequestOpened, self::without($opened, 'anchor', 'snapshot')),
            'payload.anchor.snapshot',
            'is required',
        ];
        yield 'request.opened with anchor.range.endLine of the wrong type' => [
            self::decode(EventType::RequestOpened, array_replace_recursive($opened, ['anchor' => ['range' => ['endLine' => '55']]])),
            'payload.anchor.range.endLine',
            'must be an integer',
        ];
        yield 'request.opened with anchor.snapshot.lines.1 of the wrong type' => [
            self::decode(EventType::RequestOpened, array_replace_recursive($opened, ['anchor' => ['snapshot' => ['lines' => [1 => 1]]]])),
            'payload.anchor.snapshot.lines.1',
            'must be a string',
        ];
        yield 'commit.linked with requestIds.1 of the wrong type' => [
            self::decode(EventType::CommitLinked, array_replace_recursive($linked, ['requestIds' => [1 => 1]])),
            'payload.requestIds.1',
            'must be a string',
        ];
        yield 'request.opened with an unknown anchor.kind' => [
            self::decode(EventType::RequestOpened, array_replace_recursive($opened, ['anchor' => ['kind' => 'block']])),
            'payload.anchor.kind',
            'must be one of line, range, symbol, file',
        ];
        yield 'request.opened with an unknown anchor.symbol.kind' => [
            self::decode(EventType::RequestOpened, array_replace_recursive($opened, ['anchor' => ['kind' => 'symbol', 'symbol' => $symbol]])),
            'payload.anchor.symbol.kind',
            'must be one of class, interface',
        ];
        yield 'an unknown actor.kind' => [
            static fn (): mixed => ActorCodec::decode(PayloadReader::of(['kind' => 'robot', 'userId' => null, 'agentName' => null], 'actor')),
            'actor.kind',
            'must be one of user, agent, system',
        ];
        yield 'an unknown event type' => [
            static fn (): mixed => EventCodec::decode('request.deleted', EventFixtures::json(EventType::RequestClaimed)),
            'type',
            'must be a known event type',
        ];
    }

    /**
     * @param \Closure(): mixed $decode
     * @param non-empty-string  $expectedMessageStart
     */
    #[DataProvider('corruptValues')]
    #[TestDox('A missing required field, a wrong type, an unknown enum value, and an unknown event type each throw an error that names the field')]
    public function testACorruptValueThrowsAnErrorThatNamesTheField(\Closure $decode, string $expectedField, string $expectedMessageStart): void
    {
        try {
            $decode();
        } catch (InvalidInput $invalidInput) {
            $violations = $invalidInput->violations();
            self::assertCount(1, $violations);
            self::assertSame($expectedField, $violations[0]->field);
            self::assertStringStartsWith($expectedMessageStart, $violations[0]->message);

            return;
        }

        self::fail('InvalidInput was not thrown.');
    }

    /**
     * @return iterable<string, array{EventType, array<mixed>, array<mixed>}>
     */
    public static function tolerableValues(): iterable
    {
        $opened = EventFixtures::payload(EventType::RequestOpened);
        yield 'request.opened with an unknown field' => [EventType::RequestOpened, [...$opened, self::UNKNOWN_FIELD => ['kind' => 'x']], $opened];

        foreach (EventType::cases() as $type) {
            $payload = EventFixtures::payload($type);
            foreach (self::NULLABLE_KEYS[$type->value] ?? [] as $key) {
                yield $type->value.' without '.$key => [$type, array_diff_key($payload, [$key => true]), [...$payload, $key => null]];
            }
        }

        $commitSha = 'e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e';
        $requestIds = ['01K6B3P2A9QRSTVWXYZ0123457'];

        yield 'request.opened with unknown anchor fields' => [
            EventType::RequestOpened,
            array_replace_recursive($opened, ['anchor' => [self::UNKNOWN_FIELD => 1, 'range' => [self::UNKNOWN_FIELD => 1], 'snapshot' => [self::UNKNOWN_FIELD => 1]]]),
            $opened,
        ];
        yield 'request.opened without anchor.symbol' => [EventType::RequestOpened, self::without($opened, 'anchor', 'symbol'), $opened];
        yield 'commit.linked pending without note, subject, and committedAt' => [
            EventType::CommitLinked,
            ['commitSha' => $commitSha, 'requestIds' => $requestIds, 'available' => false],
            ['commitSha' => $commitSha, 'requestIds' => $requestIds, 'note' => null, 'available' => false, 'subject' => null, 'committedAt' => null],
        ];
    }

    /**
     * @param array<mixed> $storedPayload
     * @param array<mixed> $expectedPayload
     */
    #[DataProvider('tolerableValues')]
    #[TestDox('A missing nullable field reads as null, and an unknown field is ignored')]
    public function testAMissingNullableFieldReadsAsNullAndAnUnknownFieldIsIgnored(EventType $type, array $storedPayload, array $expectedPayload): void
    {
        self::assertSame($expectedPayload, EventCodec::fromPayload($type->value, $storedPayload)->toPayload());
    }

    /**
     * @param array<mixed> $payload
     *
     * @return \Closure(): mixed
     */
    private static function decode(EventType $type, array $payload): \Closure
    {
        return static fn (): mixed => EventCodec::fromPayload($type->value, $payload);
    }

    private static function wrongType(mixed $value): mixed
    {
        return match (true) {
            \is_string($value), null === $value => 1,
            \is_int($value) => '1',
            \is_bool($value) => 'true',
            \is_array($value) => 'x',
            default => throw new \LogicException('No fixture holds a '.get_debug_type($value).'.'),
        };
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<mixed>
     */
    private static function without(array $payload, string $key, string ...$nestedKeys): array
    {
        if ([] === $nestedKeys) {
            unset($payload[$key]);

            return $payload;
        }
        $child = $payload[$key] ?? null;
        $payload[$key] = \is_array($child) ? self::without($child, ...$nestedKeys) : throw new \LogicException($key.' is not an object.');

        return $payload;
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<mixed>
     */
    private static function with(array $payload, mixed $value, string $key, string ...$nestedKeys): array
    {
        if ([] === $nestedKeys) {
            $payload[$key] = $value;

            return $payload;
        }
        $child = $payload[$key] ?? null;
        $payload[$key] = \is_array($child) ? self::with($child, $value, ...$nestedKeys) : throw new \LogicException($key.' is not an object.');

        return $payload;
    }

    /**
     * The keys and value of every field below the top level, depth first, for example [['anchor', 'range', 'endLine'], 55].
     *
     * @param array<mixed> $object
     * @param list<string> $keys   the keys of $object
     *
     * @return iterable<array{non-empty-list<string>, mixed}>
     */
    private static function nestedFields(array $object, array $keys = []): iterable
    {
        foreach ($object as $key => $value) {
            $valueKeys = [...$keys, (string) $key];
            if ([] !== $keys) {
                yield [$valueKeys, $value];
            }
            if (\is_array($value) && !array_is_list($value)) {
                yield from self::nestedFields($value, $valueKeys);
            }
        }
    }
}
