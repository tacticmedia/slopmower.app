<?php

declare(strict_types=1);

namespace App\Tests\Functional\Persistence;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Anchor\ContentSnapshot;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SymbolKind;
use App\Domain\Anchor\SymbolRef;
use App\Domain\Event\BatchSubmittedEvent;
use App\Domain\Event\CommitFoundEvent;
use App\Domain\Event\CommitLinkedEvent;
use App\Domain\Event\CommitUnlinkedEvent;
use App\Domain\Event\DomainEvent;
use App\Domain\Event\RecordedEvent;
use App\Domain\Event\RequestClaimedEvent;
use App\Domain\Event\RequestCommentedEvent;
use App\Domain\Event\ReviewClosedEvent;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Awaiting;
use App\Domain\Request\Proposal;
use App\Domain\Request\ProposalState;
use App\Domain\Request\PullRequestUrl;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Resolution;
use App\Domain\Request\SuggestionState;
use App\Domain\Review\ReviewSnapshot;
use App\Entity\ChangeRequest;
use App\Entity\Comment;
use App\Entity\CommitLink;
use App\Entity\Review;
use App\Entity\ReviewEvent;
use App\Repository\ReviewEventRepository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EntityRoundTripTest extends KernelTestCase
{
    use ResetDatabase;
    use ReviewDataFixtures;

    private const string BLOB = '3b18e512dba79e4c8300dd08aeb37f8e728b8dad';
    private const string HASH = 'sha256:4cd0f94b186ef4903963fa0397ae6175cd9f82d31d4512d8e9b67a2b3a0142f9';

    #[TestDox('Each entity reloads with equal values')]
    public function testEachEntityReloadsWithEqualValues(): void
    {
        $tenantId = self::enterNewTenant();
        $agent = RequestScenario::claimingAgent();
        $time = new \DateTimeImmutable('2026-09-29T08:15:30Z');
        $review = self::newReview($tenantId);
        $review->project(new ReviewSnapshot($review->getId(), $review->getTitle(), $review->getRef(), $review->getCommitSha(), $review->getStartedBy(), $review->getCreatedAt(), $time, RequestScenario::user(), 'Shipped in 2.4'), 9);
        $claimed = self::newRequest($review, self::symbolAnchor());
        $claimed->project(RequestStatus::InProgress, RequestStatus::Reopened, $agent, $time, null, null, Awaiting::Agent, 7, 6, true, 8, $time, true, new Proposal(EventId::generate(), ProposalState::Pending), SuggestionState::Accepted, [PullRequestUrl::fromString(RequestScenario::PULL_REQUEST)], 'Use Money for totals');
        $wontDo = self::newRequest($review, self::symbolAnchor());
        $wontDo->project(RequestStatus::WontDo, null, null, null, ActorKind::Agent, Resolution::WontDo, Awaiting::User, null, 5, false, 5, $time, false, null, null, [], null);
        $comment = new Comment(CommentId::generate(), $claimed, $agent, 'Should this also cover the shipping totals?', true, BatchId::generate(), $time);
        $pending = new CommitLink($claimed, CommitSha::fromString(RequestScenario::OTHER_COMMIT), $agent, $time, 'Extracted TaxRounding.');
        $available = new CommitLink($claimed, CommitSha::fromString(RequestScenario::LINKED_COMMIT), $agent, $time, null);
        $available->found($time->modify('+1 minute'), 'Use Money for invoice totals', new \DateTimeImmutable('2026-09-29T07:44:10Z'));
        $entities = [$review->getRepository(), $review, $claimed, $wontDo, $comment, $pending, $available];

        foreach ($entities as $entity) {
            self::entityManager()->persist($entity);
        }

        self::entityManager()->flush();
        self::entityManager()->clear();

        foreach ($entities as $entity) {
            $reloaded = self::entityManager()->find($entity::class, self::entityManager()->getClassMetadata($entity::class)->getIdentifierValues($entity));

            self::assertNotNull($reloaded);
            self::assertNotSame($entity, $reloaded);
            self::assertEquals(self::fieldValues($entity), self::fieldValues($reloaded), $entity::class);
        }

        $requestId = $claimed->getId();
        $otherRequestId = $wontDo->getId();
        $linkedSha = CommitSha::fromString(RequestScenario::LINKED_COMMIT);
        $events = [
            self::recorded($review, 1, $requestId, new RequestClaimedEvent()),
            self::recorded($review, 2, $requestId, new RequestCommentedEvent(CommentId::generate(), 'Done?', true, BatchId::generate())),
            self::recorded($review, 3, null, new CommitLinkedEvent($linkedSha, [$requestId, $otherRequestId], null, false, null, null)),
            self::recorded($review, 4, null, new CommitFoundEvent($linkedSha, [$otherRequestId, $requestId], 'Use Money for invoice totals', $time), Actor::system()),
            self::recorded($review, 5, null, new CommitUnlinkedEvent($linkedSha, $otherRequestId)),
            self::recorded($review, 6, null, new BatchSubmittedEvent(BatchId::generate(), null, [$otherRequestId], [CommentId::generate()])),
            self::recorded($review, 7, null, new ReviewClosedEvent('Shipped in 2.4')),
        ];

        foreach ($events as $event) {
            self::insertEvent($event);
        }

        $reloadedEvents = self::getContainer()->get(ReviewEventRepository::class)->findBy([], ['seq' => 'ASC']);

        self::assertEquals($events, array_map(static fn (ReviewEvent $event): RecordedEvent => $event->toRecordedEvent(), $reloadedEvents));
        self::assertEquals([[$requestId], [$requestId], [$requestId, $otherRequestId], [$otherRequestId, $requestId], [$otherRequestId], [$otherRequestId], []], array_map(static fn (ReviewEvent $event): array => $event->getRequestIds(), $reloadedEvents));
    }

    #[TestDox('Anchors with a symbol and a truncated snapshot reload unchanged')]
    #[DataProvider('anchors')]
    public function testAnchorsReloadUnchanged(Anchor $anchor): void
    {
        $tenantId = self::enterNewTenant();
        $request = self::newRequest(self::newReview($tenantId), $anchor);
        self::entityManager()->persist($request);
        self::entityManager()->flush();
        self::entityManager()->clear();

        $reloaded = self::entityManager()->find(ChangeRequest::class, self::entityManager()->getClassMetadata(ChangeRequest::class)->getIdentifierValues($request));

        self::assertEquals($anchor, $reloaded?->getAnchor() ?? self::fail('The request did not reload.'));
    }

    /**
     * @return iterable<string, array{Anchor}>
     */
    public static function anchors(): iterable
    {
        yield 'symbol' => [self::symbolAnchor()];

        $lines = array_map(static fn (int $line): string => "\t\$label = \"Größe {$line}\\n\"; // \u{2028} {}[]", range(1, 250));
        $snapshot = ContentSnapshot::of([...array_slice($lines, 0, 100), ...array_slice($lines, -100)], true, 250, [], ['}'], self::HASH);

        yield 'truncated snapshot' => [Anchor::of(AnchorKind::Range, RepoPath::fromString('src/Billing/Größe.php'), CommitSha::fromString(RequestScenario::LINKED_COMMIT), BlobSha::fromString(self::BLOB), LineRange::of(1, 250), null, $snapshot)];
    }

    #[TestDox('All actor kinds reload unchanged')]
    #[DataProvider('actors')]
    public function testAllActorKindsReloadUnchanged(Actor $actor): void
    {
        $tenantId = self::enterNewTenant();
        $request = self::newRequest(self::newReview($tenantId), self::symbolAnchor());
        $comment = new Comment(CommentId::generate(), $request, $actor, 'Fetched.', false, null, new \DateTimeImmutable(self::TIME));
        self::entityManager()->persist($request);
        self::entityManager()->persist($comment);
        self::entityManager()->flush();
        self::entityManager()->clear();

        $reloaded = self::entityManager()->find(Comment::class, self::entityManager()->getClassMetadata(Comment::class)->getIdentifierValues($comment));

        self::assertEquals($actor, $reloaded?->getAuthor());
    }

    /**
     * @return iterable<string, array{Actor}>
     */
    public static function actors(): iterable
    {
        yield 'user' => [RequestScenario::user()];
        yield 'agent' => [RequestScenario::claimingAgent()];
        yield 'system' => [Actor::system()];
    }

    private static function newRequest(Review $review, Anchor $anchor): ChangeRequest
    {
        return new ChangeRequest(RequestId::generate(), $review, $anchor, 'Use `Money` instead of `float` for totals.', RequestScenario::user(), new \DateTimeImmutable(self::TIME));
    }

    private static function symbolAnchor(): Anchor
    {
        $lines = ['    public function total(): float', '    {', '        return array_sum($this->amounts);', '    }'];

        return Anchor::of(
            AnchorKind::Symbol,
            RepoPath::fromString('src/Billing/InvoiceTotals.php'),
            CommitSha::fromString(self::REVIEW_COMMIT),
            BlobSha::fromString(self::BLOB),
            LineRange::of(38, 41),
            SymbolRef::of('php', SymbolKind::Method, 'App\Billing\InvoiceTotals::total()'),
            ContentSnapshot::of($lines, false, 4, ['    }', '', '    /** Sum of all invoice lines. */'], [], self::HASH),
        );
    }

    private static function recorded(Review $review, int $seq, ?RequestId $requestId, DomainEvent $event, ?Actor $actor = null): RecordedEvent
    {
        return new RecordedEvent(EventId::generate(), $review->getTenantId(), $seq, $review->getId(), $requestId, $actor ?? RequestScenario::claimingAgent(), new \DateTimeImmutable(self::TIME)->modify("+{$seq} minutes"), $event);
    }

    /**
     * Every mapped field, embedded fields included, and the identifiers of the associations.
     *
     * @return array<string, mixed>
     */
    private static function fieldValues(object $entity): array
    {
        $metadata = self::entityManager()->getClassMetadata($entity::class);
        $values = [];

        foreach ($metadata->getFieldNames() as $field) {
            $values[$field] = $metadata->getFieldValue($entity, $field);
        }

        foreach ($metadata->getAssociationNames() as $association) {
            $associated = $metadata->getFieldValue($entity, $association);
            $values[$association] = null === $associated ? null : self::entityManager()->getClassMetadata($metadata->getAssociationTargetClass($association))->getIdentifierValues($associated);
        }

        return $values;
    }
}
