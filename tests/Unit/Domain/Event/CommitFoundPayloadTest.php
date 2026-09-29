<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Event\Codec\EventCodec;
use App\Domain\Event\CommitFound;
use App\Domain\Event\EventType;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommitFoundPayloadTest extends KernelTestCase
{
    use ViolatedFields;

    private const string COMMIT_SHA = 'e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e';
    /** Descending, so a reader that sorts fails. */
    private const array REQUEST_IDS = ['01K6B3P2A9QRSTVWXYZ0123457', '01K6B3P2A9QRSTVWXYZ0123456'];
    private const string SUBJECT = 'Extract the tax rounding into TaxRounding';
    private const string COMMITTED_AT = '2026-09-29T07:52:30Z';

    #[TestDox('Carries the commit SHA, the linked request IDs, the subject, and the commit time')]
    public function testCarriesTheCommitShaTheLinkedRequestIdsTheSubjectAndTheCommitTime(): void
    {
        $fixture = EventFixtures::json(EventType::CommitFound);
        $event = EventCodec::decode('commit.found', $fixture);

        self::assertInstanceOf(CommitFound::class, $event);
        self::assertSame(
            [self::COMMIT_SHA, self::REQUEST_IDS, self::SUBJECT],
            [$event->commitSha->toString(), array_map(strval(...), $event->requestIds), $event->subject],
        );
        self::assertEquals(new \DateTimeImmutable(self::COMMITTED_AT), $event->committedAt);
        self::assertEquals(self::commitFound(self::SUBJECT, new \DateTimeImmutable(self::COMMITTED_AT)), $event);
        self::assertSame($fixture, EventCodec::encode($event));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function invalidPayloads(): iterable
    {
        $payload = ['commitSha' => self::COMMIT_SHA, 'requestIds' => self::REQUEST_IDS, 'subject' => self::SUBJECT, 'committedAt' => self::COMMITTED_AT];

        yield 'empty requestIds' => [[...$payload, 'requestIds' => []], ['payload.requestIds']];
        yield 'duplicate requestIds' => [[...$payload, 'requestIds' => [...self::REQUEST_IDS, self::REQUEST_IDS[0]]], ['payload.requestIds']];
        yield 'missing subject' => [array_diff_key($payload, ['subject' => true]), ['payload.subject']];
        yield 'missing commit time' => [array_diff_key($payload, ['committedAt' => true]), ['payload.committedAt']];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $expectedFields
     */
    #[DataProvider('invalidPayloads')]
    #[TestDox('Requires the subject and the commit time, and rejects empty or duplicate requestIds')]
    public function testRejectsAnIncompletePayload(array $payload, array $expectedFields): void
    {
        self::assertSame($expectedFields, self::violatedFields(static fn (): CommitFound => CommitFound::fromPayload($payload)));
    }

    #[TestDox('Rejects a new event without requestIds')]
    public function testRejectsANewEventWithoutRequestIds(): void
    {
        self::assertSame(['payload.requestIds'], self::violatedFields(static fn (): CommitFound => CommitFound::of(
            CommitSha::fromString(self::COMMIT_SHA),
            [],
            self::SUBJECT,
            new \DateTimeImmutable(self::COMMITTED_AT),
        )));
    }

    /**
     * @return iterable<string, array{\DateTimeImmutable}>
     */
    public static function commitTimesOutsideTheYearRange(): iterable
    {
        yield 'year 10000' => [new \DateTimeImmutable('@253402300800')];
    }

    #[DataProvider('commitTimesOutsideTheYearRange')]
    #[TestDox('Rejects a commit time with a UTC year outside 0000-9999, which the payload cannot hold')]
    public function testRejectsACommitTimeOutsideTheYearRange(\DateTimeImmutable $committedAt): void
    {
        self::assertSame(['payload.committedAt'], self::violatedFields(static fn (): CommitFound => self::commitFound(self::SUBJECT, $committedAt)));
        self::assertSame(['input.requestIds', 'input.committedAt'], self::violatedFields(
            static fn (): CommitFound => CommitFound::of(CommitSha::fromString(self::COMMIT_SHA), [], self::SUBJECT, $committedAt, 'input'),
        ));
    }

    #[TestDox('Replaces invalid UTF-8 and NUL in the git subject, so the event encodes and round-trips')]
    public function testReplacesInvalidUtf8AndNulInTheSubject(): void
    {
        $event = self::commitFound("Caf\xE9 totals\0", new \DateTimeImmutable(self::COMMITTED_AT));

        self::assertSame("Caf\u{FFFD} totals\u{FFFD}", $event->subject);
        self::assertEquals($event, EventCodec::decode('commit.found', EventCodec::encode($event)));
    }

    private static function commitFound(string $subject, \DateTimeImmutable $committedAt): CommitFound
    {
        return CommitFound::of(
            CommitSha::fromString(self::COMMIT_SHA),
            array_map(static fn (string $id): RequestId => RequestId::fromString($id), self::REQUEST_IDS),
            $subject,
            $committedAt,
        );
    }
}
