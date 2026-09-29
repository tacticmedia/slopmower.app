<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event\Codec;

use App\Domain\Anchor\AnchorKind;
use App\Domain\Error\InvalidInput;
use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\RequestId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PayloadReaderTest extends KernelTestCase
{
    private const string ULID = '01K6B3P1Z8Y7X6W5V4T3S2R1Q0';
    private const string OTHER_ULID = '01K6B3P2A9QRSTVWXYZ0123456';
    private const string SHA = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091';

    #[TestDox('Reads each field type as its PHP type')]
    public function testReadsEachFieldTypeAsItsPhpType(): void
    {
        $reader = PayloadReader::of([
            'body' => 'Use Money.',
            'lineCount' => 12,
            'question' => false,
            'lines' => ['    {', ''],
            'kind' => 'symbol',
            'committedAt' => '2026-09-29T10:11:12Z',
            'requestId' => strtolower(self::ULID),
            'requestIds' => [self::ULID, self::OTHER_ULID],
            'commitSha' => self::SHA,
            'blobSha' => self::SHA,
            'snapshot' => [],
        ]);

        self::assertSame('Use Money.', $reader->string('body'));
        self::assertSame(12, $reader->int('lineCount'));
        self::assertFalse($reader->bool('question'));
        self::assertSame(['    {', ''], $reader->stringList('lines'));
        self::assertSame(AnchorKind::Symbol, $reader->enum('kind', AnchorKind::class));
        self::assertSame('2026-09-29 10:11:12 UTC', $reader->dateTime('committedAt')->format('Y-m-d H:i:s e'));
        self::assertSame(self::ULID, $reader->id('requestId', RequestId::class)->toString());
        self::assertSame([self::ULID, self::OTHER_ULID], array_map(strval(...), $reader->idList('requestIds', RequestId::class)));
        self::assertSame(self::SHA, $reader->commitSha('commitSha')->toString());
        self::assertSame(self::SHA, $reader->parsed('blobSha', BlobSha::fromString(...))->toString());
        self::assertSame('payload.snapshot', $reader->object('snapshot')->path());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function payloadsWithoutNote(): iterable
    {
        yield 'missing' => [['addedInALaterRelease' => ['x' => 1]]];
        yield 'null' => [['note' => null, 'addedInALaterRelease' => 'x']];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('payloadsWithoutNote')]
    #[TestDox('Reads a missing or null nullable field as null and ignores unknown fields')]
    public function testReadsAMissingOrNullNullableFieldAsNull(array $payload): void
    {
        $reader = PayloadReader::of($payload);

        self::assertSame(
            [null, null, null, null, null],
            [
                $reader->nullableString('note'),
                $reader->nullableObject('note'),
                $reader->nullableDateTime('note'),
                $reader->nullableId('note', BatchId::class),
                $reader->nullableParsed('note', BlobSha::fromString(...)),
            ],
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, \Closure(PayloadReader): mixed, string, string}>
     */
    public static function corruptValues(): iterable
    {
        $mustBeAUlid = 'must be a ULID of 26 Crockford base32 characters';

        yield 'missing string' => [[], static fn (PayloadReader $reader): string => $reader->string('body'), 'payload.body', 'is required'];
        yield 'null string' => [['body' => null], static fn (PayloadReader $reader): string => $reader->string('body'), 'payload.body', 'must be a string'];
        yield 'number as string' => [['body' => 1], static fn (PayloadReader $reader): ?string => $reader->nullableString('body'), 'payload.body', 'must be a string'];
        yield 'string as integer' => [['lineCount' => '12'], static fn (PayloadReader $reader): int => $reader->int('lineCount'), 'payload.lineCount', 'must be an integer'];
        yield 'float as integer' => [['lineCount' => 12.0], static fn (PayloadReader $reader): int => $reader->int('lineCount'), 'payload.lineCount', 'must be an integer'];
        yield 'missing boolean' => [[], static fn (PayloadReader $reader): bool => $reader->bool('question'), 'payload.question', 'is required'];
        yield 'string as boolean' => [['question' => 'true'], static fn (PayloadReader $reader): bool => $reader->bool('question'), 'payload.question', 'must be a boolean'];
        yield 'object as list' => [['lines' => ['a' => 'b']], static fn (PayloadReader $reader): array => $reader->stringList('lines'), 'payload.lines', 'must be a list'];
        yield 'number in a list' => [['lines' => ['a', 2]], static fn (PayloadReader $reader): array => $reader->stringList('lines'), 'payload.lines.1', 'must be a string'];
        yield 'list as object' => [['range' => [1, 2]], static fn (PayloadReader $reader): PayloadReader => $reader->object('range'), 'payload.range', 'must be an object'];
        yield 'string as object' => [['range' => 'x'], static fn (PayloadReader $reader): ?PayloadReader => $reader->nullableObject('range'), 'payload.range', 'must be an object'];
        yield 'unknown enum value' => [['kind' => 'block'], static fn (PayloadReader $reader): AnchorKind => $reader->enum('kind', AnchorKind::class), 'payload.kind', 'must be one of line, range, symbol, file'];
        yield 'number as enum' => [['kind' => 1], static fn (PayloadReader $reader): AnchorKind => $reader->enum('kind', AnchorKind::class), 'payload.kind', 'must be a string'];
        yield 'invalid ID' => [['batchId' => 'x'], static fn (PayloadReader $reader): ?BatchId => $reader->nullableId('batchId', BatchId::class), 'payload.batchId', $mustBeAUlid];
        yield 'invalid ID in a list' => [['requestIds' => [self::ULID, 'x']], static fn (PayloadReader $reader): array => $reader->idList('requestIds', RequestId::class), 'payload.requestIds.1', $mustBeAUlid];
        yield 'upper-case SHA' => [['commitSha' => strtoupper(self::SHA)], static fn (PayloadReader $reader): CommitSha => $reader->commitSha('commitSha'), 'payload.commitSha', 'must be 40 or 64 lower-case hex characters'];
        yield 'date-time with an offset' => [
            ['committedAt' => '2026-09-29T10:11:12+00:00'],
            static fn (PayloadReader $reader): ?\DateTimeImmutable => $reader->nullableDateTime('committedAt'),
            'payload.committedAt',
            'must be an RFC 3339 UTC date-time with second precision and a Z suffix',
        ];
        yield 'nested value' => [
            ['anchor' => ['range' => ['startLine' => 1, 'endLine' => '2']]],
            static fn (PayloadReader $reader): int => $reader->object('anchor')->object('range')->int('endLine'),
            'payload.anchor.range.endLine',
            'must be an integer',
        ];
    }

    /**
     * @param array<string, mixed>           $payload
     * @param \Closure(PayloadReader): mixed $read
     */
    #[DataProvider('corruptValues')]
    #[TestDox('Names the dotted field of a missing, wrongly typed, or invalid value')]
    public function testNamesTheDottedFieldOfACorruptValue(array $payload, \Closure $read, string $expectedField, string $expectedMessage): void
    {
        try {
            $read(PayloadReader::of($payload));
        } catch (InvalidInput $invalidInput) {
            self::assertSame([['field' => $expectedField, 'message' => $expectedMessage]], $invalidInput->details()['violations']);

            return;
        }

        self::fail('InvalidInput was not thrown.');
    }

    #[TestDox('Passes the value and its dotted field to a parser')]
    public function testPassesTheValueAndItsDottedFieldToAParser(): void
    {
        $anchor = PayloadReader::of(['anchor' => ['ref' => 'main']])->object('anchor');

        self::assertSame(['main', 'payload.anchor.ref'], $anchor->parsed('ref', static fn (string $value, string $field): array => [$value, $field]));
    }

    #[TestDox('Starts its dotted fields at the given path')]
    public function testStartsItsDottedFieldsAtTheGivenPath(): void
    {
        self::assertSame('actor.userId', PayloadReader::of([], 'actor')->field('userId'));
        self::assertSame('userId', PayloadReader::of([], '')->field('userId'));
        self::assertSame('payload.userId', PayloadReader::of([])->field('userId'));
    }
}
