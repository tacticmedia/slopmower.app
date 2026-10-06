<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Anchor\ContentSnapshot;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SymbolKind;
use App\Domain\Anchor\SymbolRef;
use App\Domain\Event\BatchSubmittedEvent;
use App\Domain\Event\Codec\PayloadJson;
use App\Domain\Event\CommitFoundEvent;
use App\Domain\Event\CommitLinkedEvent;
use App\Domain\Event\CommitUnlinkedEvent;
use App\Domain\Event\DomainEvent;
use App\Domain\Event\EventType;
use App\Domain\Event\RequestClaimedEvent;
use App\Domain\Event\RequestCommentedEvent;
use App\Domain\Event\RequestOpenedEvent;
use App\Domain\Event\RequestReadyForReviewEvent;
use App\Domain\Event\RequestReleasedEvent;
use App\Domain\Event\RequestReopenedEvent;
use App\Domain\Event\RequestVerifiedEvent;
use App\Domain\Event\RequestWontDoEvent;
use App\Domain\Event\ReviewClosedEvent;
use App\Domain\Event\ReviewStartedEvent;
use App\Domain\Event\WontDoReason;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\RequestId;
use App\Tests\Support\Domain\EventFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EventRoundTripTest extends KernelTestCase
{
    use EventFixtures;

    private const string REVIEW_COMMIT = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091';
    private const string LINKED_COMMIT = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    private const string PENDING_COMMIT = 'e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e';
    private const string REQUEST_A = '01K6B3P2A9QRSTVWXYZ0123456';
    private const string REQUEST_B = '01K6B3P2A9QRSTVWXYZ0123457';
    private const string COMMENT = '01K6C3C0MMENT0000000000001';
    private const string BATCH = '01K6B3P1Z8Y7X6W5V4T3S2R1Q0';

    #[TestDox('fromPayload(toPayload(event)) equals the event')]
    #[DataProvider('events')]
    public function testRoundTrips(string $fixture, DomainEvent $event): void
    {
        $type = EventType::from($event::type()->value);

        self::assertInstanceOf($type->eventClass(), $event);
        self::assertEquals($event, $type->decode($event->toPayload()));
    }

    #[TestDox('The encoded JSON equals the fixture string')]
    #[DataProvider('events')]
    public function testEncodesTheFixture(string $fixture, DomainEvent $event): void
    {
        $json = self::eventFixtureJson($fixture);

        self::assertSame($json, PayloadJson::encode($event->toPayload()));
    }

    /**
     * @return iterable<string, array{string, DomainEvent}>
     */
    public static function events(): iterable
    {
        foreach (EventType::cases() as $type) {
            yield $type->value => [$type->value, self::example($type)];
        }

        yield 'request.opened with a file anchor' => ['request.opened-file', new RequestOpenedEvent(
            Anchor::of(AnchorKind::File, RepoPath::fromString('composer.lock'), CommitSha::fromString(self::REVIEW_COMMIT), BlobSha::fromString('8c3e2a1f0b9d8c7e6f5a4b3c2d1e0f9a8b7c6d5e'), null, null, null),
            'Do not commit the lock file changes.',
            null,
        )];

        yield 'request.opened without an anchor' => ['request.opened-general', new RequestOpenedEvent(null, 'Add a full-screen focus mode.', null)];
    }

    private static function example(EventType $type): DomainEvent
    {
        return match ($type) {
            EventType::ReviewStarted => new ReviewStartedEvent('Billing refactor', RefName::fromString('main'), CommitSha::fromString(self::REVIEW_COMMIT)),
            EventType::ReviewClosed => new ReviewClosedEvent('All requests are verified.'),
            EventType::RequestOpened => self::architectureExample(),
            EventType::RequestCommented => new RequestCommentedEvent(CommentId::fromString(self::COMMENT), 'Should the rounding use HALF_EVEN?', true, null),
            EventType::RequestClaimed => new RequestClaimedEvent(),
            EventType::RequestReleased => new RequestReleasedEvent('Blocked by the open tax question.'),
            EventType::CommitLinked => new CommitLinkedEvent(
                CommitSha::fromString(self::LINKED_COMMIT),
                [RequestId::fromString(self::REQUEST_A), RequestId::fromString(self::REQUEST_B)],
                'One refactoring covers both.',
                true,
                'Use Money for invoice totals',
                new \DateTimeImmutable('2026-09-29T07:44:10Z'),
            ),
            EventType::CommitUnlinked => new CommitUnlinkedEvent(CommitSha::fromString(self::LINKED_COMMIT), RequestId::fromString(self::REQUEST_B)),
            EventType::CommitFound => new CommitFoundEvent(
                CommitSha::fromString(self::PENDING_COMMIT),
                [RequestId::fromString(self::REQUEST_A)],
                'Extract TaxRounding',
                new \DateTimeImmutable('2026-09-29T08:02:31Z'),
            ),
            EventType::RequestReadyForReview => new RequestReadyForReviewEvent('Totals use Money. Tests cover the rounding.'),
            EventType::RequestWontDo => new RequestWontDoEvent(WontDoReason::fromString('The PDF library API requires float amounts.')),
            EventType::RequestVerified => new RequestVerifiedEvent(null),
            EventType::RequestReopened => new RequestReopenedEvent('The tax line still uses float: 0.1 + 0.2 ≠ 0.3.'),
            EventType::BatchSubmitted => new BatchSubmittedEvent(
                BatchId::fromString(self::BATCH),
                'First pass over billing.',
                [RequestId::fromString(self::REQUEST_A), RequestId::fromString(self::REQUEST_B)],
                [CommentId::fromString(self::COMMENT)],
            ),
        };
    }

    private static function architectureExample(): RequestOpenedEvent
    {
        $snapshot = ContentSnapshot::of(
            [
                '    /**',
                '     * Sum of all invoice lines.',
                '     */',
                '    public function total(): float',
                '    {',
                '        $sum = 0.0;',
                '        foreach ($this->lines as $line) {',
                '            $sum += $line->amount;',
                '        }',
                '',
                '        return $sum;',
                '    }',
            ],
            false,
            12,
            ['    }', '', ''],
            ['', '    public function tax(): float', '    {'],
            'sha256:4cd0f94b186ef4903963fa0397ae6175cd9f82d31d4512d8e9b67a2b3a0142f9',
        );

        return new RequestOpenedEvent(
            Anchor::of(
                AnchorKind::Symbol,
                RepoPath::fromString('src/Billing/InvoiceTotals.php'),
                CommitSha::fromString(self::REVIEW_COMMIT),
                BlobSha::fromString('3b18e512dba79e4c8300dd08aeb37f8e728b8dad'),
                LineRange::of(38, 49),
                SymbolRef::of('php', SymbolKind::Method, 'App\Billing\InvoiceTotals::total()'),
                $snapshot,
            ),
            'Use `Money` instead of `float` for totals.',
            BatchId::fromString(self::BATCH),
        );
    }
}
