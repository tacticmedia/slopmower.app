<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\ContentSnapshot;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SnapshotCapture;
use App\Domain\Event\BatchSubmitted;
use App\Domain\Event\Codec\EventCodec;
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
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\RequestId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EventRoundTripTest extends KernelTestCase
{
    private const string REVIEW_COMMIT_SHA = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091';
    private const string FIX_COMMIT_SHA = 'e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e';
    private const string BLOB_SHA = '3b18e512dba79e4c8300dd08aeb37f8e728b8dad';
    private const string BATCH_ID = '01K6B3P1Z8Y7X6W5V4T3S2R1Q0';
    private const string REQUEST_ID = '01K6B3P2A9QRSTVWXYZ0123456';
    private const string OTHER_REQUEST_ID = '01K6B3P2A9QRSTVWXYZ0123457';
    private const string COMMENT_ID = '01K6B3Q4C5D6E7F8G9H0J1K2M3';
    /** A Markdown indented code block and hard break, which a trimming codec would lose. */
    private const string INDENTED_TEXT = "    \$total = 1;\n\nIndented code, then a hard break  \n";

    /**
     * The event of each fixture, built through the domain constructors.
     *
     * @return iterable<string, array{EventType, DomainEvent}>
     */
    public static function fixtureEvents(): iterable
    {
        foreach (EventType::cases() as $type) {
            yield $type->value => [$type, self::fixtureEvent($type)];
        }
    }

    /**
     * @return iterable<string, array{EventType, DomainEvent}>
     */
    public static function events(): iterable
    {
        yield from self::fixtureEvents();

        yield 'required text with whitespace around it' => [EventType::RequestOpened, new RequestOpened(self::rangeAnchor(), self::INDENTED_TEXT, null)];
        yield 'optional text with whitespace around it' => [EventType::ReviewClosed, new ReviewClosed(self::INDENTED_TEXT)];
        yield 'request.opened on a truncated region' => [EventType::RequestOpened, new RequestOpened(self::truncatedRangeAnchor(), 'Split this class.', null)];
        yield 'request.commented with a question outside a batch' => [
            EventType::RequestCommented,
            new RequestCommented(CommentId::fromString(self::COMMENT_ID), "Why \"float\"?\nSee C:\\tmp and src/Billing, \u{1F642}", true, null),
        ];
        yield 'empty optional text' => [EventType::ReviewClosed, new ReviewClosed('')];
    }

    #[DataProvider('events')]
    #[TestDox('fromPayload(toPayload(event)) equals the event')]
    public function testFromPayloadOfToPayloadEqualsTheEvent(EventType $type, DomainEvent $event): void
    {
        self::assertSame($type, $event::type());
        self::assertSame($event::class, EventCodec::eventClass($type));

        $decoded = $event::fromPayload($event->toPayload());
        self::assertEquals($event, $decoded);
        // assertEquals() takes '' and null as equal.
        self::assertSame($event->toPayload(), $decoded->toPayload());
        self::assertEquals($event, EventCodec::decode($type->value, EventCodec::encode($event)));
    }

    #[TestDox('A commit time with an offset reads back as the same UTC date-time')]
    public function testACommitTimeWithAnOffsetReadsBackAsTheSameUtcDateTime(): void
    {
        $committedAt = new \DateTimeImmutable('2026-09-29T19:41:12+09:30');
        $requestIds = [RequestId::fromString(self::REQUEST_ID)];
        $linked = CommitLinked::available(self::fixCommitSha(), $requestIds, null, 'Use Money', $committedAt);
        $found = CommitFound::of(self::fixCommitSha(), $requestIds, 'Use Money', $committedAt);

        $commitTimes = [
            $linked->committedAt,
            CommitLinked::fromPayload($linked->toPayload())->committedAt,
            $found->committedAt,
            CommitFound::fromPayload($found->toPayload())->committedAt,
        ];

        self::assertSame(
            array_fill(0, 4, '2026-09-29T10:11:12+00:00'),
            array_map(static fn (?\DateTimeImmutable $commitTime): ?string => $commitTime?->format('Y-m-d\TH:i:sP'), $commitTimes),
        );
    }

    #[DataProvider('fixtureEvents')]
    #[TestDox('The encoded JSON equals the fixture string')]
    public function testTheEncodedJsonEqualsTheFixtureString(EventType $type, DomainEvent $event): void
    {
        $fixture = EventFixtures::json($type);

        self::assertSame($fixture, EventCodec::encode($event));
        self::assertEquals($event, EventCodec::decode($type->value, $fixture));
    }

    private static function fixtureEvent(EventType $type): DomainEvent
    {
        $requestId = RequestId::fromString(self::REQUEST_ID);
        $otherRequestId = RequestId::fromString(self::OTHER_REQUEST_ID);

        return match ($type) {
            EventType::ReviewStarted => new ReviewStarted('main @ 79f8595a4c1e', RefName::fromString('main'), self::reviewCommitSha()),
            EventType::ReviewClosed => new ReviewClosed('Shipped in 2.4'),
            EventType::RequestOpened => new RequestOpened(self::rangeAnchor(), 'Extract the tax rounding into `TaxRounding`.', null),
            EventType::RequestCommented => new RequestCommented(
                CommentId::fromString(self::COMMENT_ID),
                "Please also round the shipping totals in \u{20AC}.",
                false,
                BatchId::fromString(self::BATCH_ID),
            ),
            EventType::RequestClaimed => new RequestClaimed(),
            EventType::RequestReleased => new RequestReleased('The fix needs a schema change. I stop here.'),
            EventType::CommitLinked => CommitLinked::available(
                CommitSha::fromString('a1b2c3d4e5f60718293a4b5c6d7e8f9012345678'),
                [$requestId, $otherRequestId],
                'One refactoring covers both.',
                'Use Money for invoice totals (01K6B3P2A9QRSTVWXYZ0123456)',
                new \DateTimeImmutable('2026-09-29T07:44:10Z'),
            ),
            EventType::CommitUnlinked => new CommitUnlinked(self::fixCommitSha(), $otherRequestId),
            EventType::CommitFound => CommitFound::of(
                self::fixCommitSha(),
                [$otherRequestId, $requestId],
                'Extract the tax rounding into TaxRounding',
                new \DateTimeImmutable('2026-09-29T07:52:30Z'),
            ),
            EventType::RequestReadyForReview => new RequestReadyForReview(
                'Replaced `float` with `Money` in `total()` and `tax()`. Added rounding cases to `InvoiceTotalsTest`. `make test` passes.',
            ),
            EventType::RequestWontDo => new RequestWontDo('`InvoiceTotals::tax()` already rounds half-even at line 55, and `InvoiceTotalsTest::testTaxRounding` covers it.'),
            EventType::RequestVerified => new RequestVerified(null),
            EventType::RequestReopened => new RequestReopened('`tax()` still returns a float. Return `Money` there too.'),
            EventType::BatchSubmitted => BatchSubmitted::of(
                BatchId::fromString(self::BATCH_ID),
                'First pass over billing.',
                [$requestId, $otherRequestId],
                [CommentId::fromString('01K6C3C0MMENT0000000000002'), CommentId::fromString('01K6C3C0MMENT0000000000001')],
            ),
        };
    }

    private static function truncatedRangeAnchor(): Anchor
    {
        $region = array_map(static fn (int $line): string => '    // line '.$line, range(1, 250));
        $edgeLineCount = intdiv(ContentSnapshot::MAX_LINES, 2);

        return Anchor::range(self::path(), self::reviewCommitSha(), self::blobSha(), LineRange::of(1, 250), ContentSnapshot::fromParts(
            [...\array_slice($region, 0, $edgeLineCount), ...\array_slice($region, -$edgeLineCount)],
            true,
            250,
            [],
            ['}'],
            SnapshotCapture::normalizedHash($region),
        ));
    }

    private static function rangeAnchor(): Anchor
    {
        $lines = ['        $tax = $this->total() * $this->taxRate;', '', '        return round($tax, 2, PHP_ROUND_HALF_EVEN);'];

        return Anchor::range(self::path(), self::reviewCommitSha(), self::blobSha(), LineRange::of(53, 55), ContentSnapshot::fromParts(
            $lines,
            false,
            3,
            ['', '    public function tax(): float', '    {'],
            ['    }', '', '    public function shipping(): float'],
            SnapshotCapture::normalizedHash($lines),
        ));
    }

    private static function path(): RepoPath
    {
        return RepoPath::fromString('src/Billing/InvoiceTotals.php');
    }

    private static function reviewCommitSha(): CommitSha
    {
        return CommitSha::fromString(self::REVIEW_COMMIT_SHA);
    }

    private static function fixCommitSha(): CommitSha
    {
        return CommitSha::fromString(self::FIX_COMMIT_SHA);
    }

    private static function blobSha(): BlobSha
    {
        return BlobSha::fromString(self::BLOB_SHA);
    }
}
