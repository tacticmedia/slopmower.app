<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Event\Codec\EventCodec;
use App\Domain\Event\CommitLinked;
use App\Domain\Event\EventType;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommitLinkedPayloadTest extends KernelTestCase
{
    use ViolatedFields;

    private const string COMMIT_SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    private const string REQUEST_ID = '01K6B3P2A9QRSTVWXYZ0123456';
    private const string OTHER_REQUEST_ID = '01K6B3P2A9QRSTVWXYZ0123457';
    private const string COMMITTED_AT = '2026-09-29T07:44:10Z';

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidRequestIds(): iterable
    {
        yield 'empty' => [[]];
        yield 'duplicate' => [[self::REQUEST_ID, self::OTHER_REQUEST_ID, self::REQUEST_ID]];
        yield 'duplicate in lower case' => [[self::REQUEST_ID, strtolower(self::REQUEST_ID)]];
    }

    /**
     * @param list<string> $requestIds
     */
    #[DataProvider('invalidRequestIds')]
    #[TestDox('Rejects empty or duplicate requestIds')]
    public function testRejectsEmptyOrDuplicateRequestIds(array $requestIds): void
    {
        $commitSha = CommitSha::fromString(self::COMMIT_SHA);
        $ids = array_map(static fn (string $id): RequestId => RequestId::fromString($id), $requestIds);
        $committedAt = new \DateTimeImmutable(self::COMMITTED_AT);

        self::assertSame(['payload.requestIds'], self::violatedFields(static fn (): CommitLinked => CommitLinked::pending($commitSha, $ids, null)));
        self::assertSame(['payload.requestIds'], self::violatedFields(static fn (): CommitLinked => CommitLinked::available($commitSha, $ids, null, 'Use Money', $committedAt)));
        self::assertSame(['payload.requestIds'], self::violatedFields(static fn (): CommitLinked => CommitLinked::fromPayload(
            ['commitSha' => self::COMMIT_SHA, 'requestIds' => $requestIds, 'available' => false],
        )));
    }

    #[TestDox('Carries subject and commit time only when available is true')]
    public function testCarriesSubjectAndCommitTimeOnlyWhenAvailableIsTrue(): void
    {
        $fixture = EventFixtures::json(EventType::CommitLinked);
        $available = EventCodec::decode('commit.linked', $fixture);
        $pending = CommitLinked::pending(CommitSha::fromString(self::COMMIT_SHA), [RequestId::fromString(self::REQUEST_ID)], null);

        self::assertInstanceOf(CommitLinked::class, $available);
        self::assertSame([true, 'Use Money for invoice totals (01K6B3P2A9QRSTVWXYZ0123456)'], [$available->available, $available->subject]);
        self::assertEquals(new \DateTimeImmutable(self::COMMITTED_AT), $available->committedAt);
        self::assertSame($fixture, EventCodec::encode($available));
        self::assertSame(
            ['commitSha' => self::COMMIT_SHA, 'requestIds' => [self::REQUEST_ID], 'note' => null, 'available' => false, 'subject' => null, 'committedAt' => null],
            $pending->toPayload(),
        );
        self::assertEquals($pending, CommitLinked::fromPayload($pending->toPayload()));

        $availablePayload = $available->toPayload();
        $pendingPayload = $pending->toPayload();
        self::assertSame(['payload.subject', 'payload.committedAt'], self::violatedFields(static fn (): CommitLinked => CommitLinked::fromPayload([...$pendingPayload, 'available' => true])));
        self::assertSame(['payload.subject', 'payload.committedAt'], self::violatedFields(static fn (): CommitLinked => CommitLinked::fromPayload([...$availablePayload, 'available' => false])));
        self::assertSame(['payload.subject'], self::violatedFields(static fn (): CommitLinked => CommitLinked::fromPayload([...$availablePayload, 'subject' => null])));
        self::assertSame(['payload.committedAt'], self::violatedFields(static fn (): CommitLinked => CommitLinked::fromPayload(array_diff_key($availablePayload, ['committedAt' => true]))));
        self::assertSame(['payload.committedAt'], self::violatedFields(static fn (): CommitLinked => CommitLinked::fromParts(
            $pending->commitSha,
            $pending->requestIds,
            null,
            false,
            null,
            new \DateTimeImmutable(self::COMMITTED_AT),
        )));
    }
}
