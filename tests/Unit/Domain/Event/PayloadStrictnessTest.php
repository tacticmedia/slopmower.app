<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Event\Codec\CorruptPayload;
use App\Domain\Event\EventType;
use App\Tests\Support\Domain\EventFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PayloadStrictnessTest extends KernelTestCase
{
    use EventFixtures;

    /**
     * @param array<mixed> $payload
     * @param list<string> $expectedFields
     */
    #[TestDox('A missing required field, a wrong type, an unknown enum value, and an unknown event type each throw an error that names the field')]
    #[DataProvider('corruptPayloads')]
    public function testNamesTheCorruptField(string $type, array $payload, array $expectedFields): void
    {
        try {
            EventType::fromString($type)->decode($payload);
        } catch (CorruptPayload $error) {
            self::assertSame($expectedFields, array_column($error->violations, 'field'));

            return;
        }

        self::fail('No CorruptPayload was thrown.');
    }

    /**
     * @return iterable<string, array{string, array<mixed>, list<string>}>
     */
    public static function corruptPayloads(): iterable
    {
        $started = self::eventFixturePayload('review.started');
        unset($started['title']);
        $opened = self::eventFixturePayload('request.opened');

        yield 'a missing required field' => ['review.started', $started, ['title']];
        yield 'a wrong type' => ['request.commented', ['question' => 'yes'] + self::eventFixturePayload('request.commented'), ['question']];
        yield 'a wrong list item' => ['batch.submitted', ['commentIds' => [42]] + self::eventFixturePayload('batch.submitted'), ['commentIds.0']];
        yield 'a malformed time' => ['commit.found', ['committedAt' => '2026-09-29 08:02:31'] + self::eventFixturePayload('commit.found'), ['committedAt']];
        yield 'an unknown enum value' => ['request.opened', array_replace_recursive($opened, ['anchor' => ['kind' => 'block']]), ['anchor.kind']];
        yield 'a value that an anchor rule rejects' => ['request.opened', array_replace_recursive($opened, ['anchor' => ['range' => ['endLine' => 37]]]), ['anchor.range.endLine']];
        yield 'a value that an event rule rejects' => ['request.wont_do', ['reason' => 'Too short.'], ['reason']];
        yield 'an unknown event type' => ['request.deleted', [], ['type']];
    }

    #[TestDox('A missing nullable field reads as null, and an unknown field is ignored')]
    public function testDefaultsMissingNullableFieldsAndIgnoresUnknownFields(): void
    {
        $payload = [
            'body' => 'Do not commit the lock file changes.',
            'anchor' => [
                'kind' => 'file',
                'path' => 'composer.lock',
                'commitSha' => '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091',
                'blobSha' => '8c3e2a1f0b9d8c7e6f5a4b3c2d1e0f9a8b7c6d5e',
                'futureField' => 1,
            ],
            'futureField' => ['any' => 'value'],
        ];

        self::assertEquals(
            EventType::RequestOpened->decode(self::eventFixturePayload('request.opened-file')),
            EventType::RequestOpened->decode($payload),
        );
    }
}
