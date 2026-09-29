<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Event\BatchSubmitted;
use App\Domain\Event\CommitFound;
use App\Domain\Event\CommitLinked;
use App\Domain\Event\CommitUnlinked;
use App\Domain\Event\RecordedEvent;
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
use App\Domain\Git\RefName;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\LinkedCommit;
use App\Domain\Request\RequestReducer;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReducerTest extends KernelTestCase
{
    private const string REASON = 'The float is rounded once at the end, so the totals are exact.';

    /**
     * @return iterable<string, array{RequestState|null, RecordedEvent, RequestState}>
     */
    public static function eventsAndStates(): iterable
    {
        $user = LifecycleFixtures::user();
        $agent = LifecycleFixtures::agent();
        $sha = LifecycleFixtures::sha();
        $otherSha = LifecycleFixtures::sha(LifecycleFixtures::OTHER_COMMIT);
        $requestIds = [LifecycleFixtures::requestId(), LifecycleFixtures::requestId(LifecycleFixtures::OTHER_REQUEST_ID)];
        $opened = self::state(RequestStatus::Open, lastUserEventSeq: 1, lastEventSeq: 1);
        $claimedAt = new \DateTimeImmutable(LifecycleFixtures::CLAIMED_AT);
        $inProgress = self::state(RequestStatus::InProgress, statusBeforeClaim: RequestStatus::Open, claimedBy: $agent, claimedAt: $claimedAt, lastUserEventSeq: 1, lastAgentEventSeq: 6);

        yield 'request.opened' => [null, LifecycleFixtures::recorded(1, new RequestOpened(LifecycleFixtures::fileAnchor(), 'Use Money.', null), $user), $opened];
        yield 'request.commented by the user' => [
            $opened,
            LifecycleFixtures::recorded(2, new RequestCommented(CommentId::generate(), 'Also the tax.', false, null), $user),
            self::state(RequestStatus::Open, lastUserEventSeq: 2),
        ];
        yield 'request.commented by an agent with a question' => [
            $opened,
            LifecycleFixtures::recorded(2, new RequestCommented(CommentId::generate(), 'Keep float?', true, null), $agent),
            self::state(RequestStatus::Open, lastUserEventSeq: 1, lastAgentEventSeq: 2, lastAgentEventAsksQuestion: true),
        ];
        yield 'request.claimed' => [
            self::state(RequestStatus::Reopened, lastUserEventSeq: 1, lastAgentEventSeq: 3, lastAgentEventAsksQuestion: true, lastEventSeq: 5),
            LifecycleFixtures::recorded(6, new RequestClaimed(), $agent, occurredAt: LifecycleFixtures::CLAIMED_AT),
            self::state(RequestStatus::InProgress, statusBeforeClaim: RequestStatus::Reopened, claimedBy: $agent, claimedAt: $claimedAt, lastUserEventSeq: 1, lastAgentEventSeq: 6),
        ];
        yield 'request.released by the agent' => [
            $inProgress,
            LifecycleFixtures::recorded(7, new RequestReleased('Blocked.'), $agent),
            self::state(RequestStatus::Open, lastUserEventSeq: 1, lastAgentEventSeq: 7),
        ];
        yield 'request.released by the user' => [
            $inProgress,
            LifecycleFixtures::recorded(7, new RequestReleased('Unassign'), $user),
            self::state(RequestStatus::Open, lastUserEventSeq: 7, lastAgentEventSeq: 6),
        ];
        yield 'commit.linked, pending' => [
            $inProgress,
            LifecycleFixtures::recorded(7, CommitLinked::pending($sha, $requestIds, null), $agent),
            self::inProgress(7, [new LinkedCommit($sha, CommitLinkState::Pending)]),
        ];
        yield 'commit.linked, available' => [
            $inProgress,
            LifecycleFixtures::recorded(7, CommitLinked::available($sha, $requestIds, 'Both.', 'Use Money', new \DateTimeImmutable('2026-09-29T07:44:10Z')), $agent),
            self::inProgress(7, [new LinkedCommit($sha, CommitLinkState::Available)]),
        ];
        yield 'commit.unlinked' => [
            self::inProgress(6, [new LinkedCommit($sha, CommitLinkState::Pending), new LinkedCommit($otherSha, CommitLinkState::Available)]),
            LifecycleFixtures::recorded(7, new CommitUnlinked($sha, LifecycleFixtures::requestId()), $agent),
            self::inProgress(7, [new LinkedCommit($otherSha, CommitLinkState::Available)]),
        ];
        yield 'commit.found' => [
            self::inProgress(6, [new LinkedCommit($otherSha, CommitLinkState::Pending), new LinkedCommit($sha, CommitLinkState::Pending)]),
            LifecycleFixtures::recorded(7, CommitFound::of($sha, $requestIds, 'Use Money', new \DateTimeImmutable('2026-09-29T07:44:10Z')), Actor::system()),
            self::inProgress(6, [new LinkedCommit($otherSha, CommitLinkState::Pending), new LinkedCommit($sha, CommitLinkState::Available)], lastEventSeq: 7),
        ];
        yield 'request.ready_for_review' => [
            $inProgress,
            LifecycleFixtures::recorded(7, new RequestReadyForReview('Totals use Money.'), $agent),
            self::state(RequestStatus::ReadyForReview, lastUserEventSeq: 1, lastAgentEventSeq: 7),
        ];
        yield 'request.wont_do by an agent' => [
            $inProgress,
            LifecycleFixtures::recorded(7, new RequestWontDo(self::REASON), $agent),
            self::state(RequestStatus::WontDo, wontDoSetBy: ActorKind::Agent, lastUserEventSeq: 1, lastAgentEventSeq: 7),
        ];
        yield 'request.wont_do by the user' => [
            $opened,
            LifecycleFixtures::recorded(2, new RequestWontDo(self::REASON), $user),
            self::state(RequestStatus::WontDo, wontDoSetBy: ActorKind::User, lastUserEventSeq: 2),
        ];
        yield 'request.verified' => [
            self::state(RequestStatus::ReadyForReview, lastUserEventSeq: 1, lastAgentEventSeq: 7),
            LifecycleFixtures::recorded(8, new RequestVerified(null), $user),
            self::state(RequestStatus::Verified, verifiedFrom: RequestStatus::ReadyForReview, lastUserEventSeq: 8, lastAgentEventSeq: 7),
        ];
        yield 'request.reopened' => [
            self::state(RequestStatus::Verified, verifiedFrom: RequestStatus::WontDo, lastUserEventSeq: 1, lastAgentEventSeq: 7, lastEventSeq: 8),
            LifecycleFixtures::recorded(9, new RequestReopened('The rounding is still wrong.'), $user),
            self::state(RequestStatus::Reopened, lastUserEventSeq: 9, lastAgentEventSeq: 7),
        ];
    }

    #[DataProvider('eventsAndStates')]
    #[TestDox('Every event type updates the state fields')]
    public function testEveryEventTypeUpdatesTheStateFields(?RequestState $before, RecordedEvent $recorded, RequestState $expected): void
    {
        self::assertEquals($expected, RequestReducer::apply($before, $recorded));
    }

    #[TestDox('Review and batch events change no request state')]
    public function testReviewAndBatchEventsChangeNoRequest(): void
    {
        $user = LifecycleFixtures::user();
        $opened = LifecycleFixtures::recorded(2, new RequestOpened(LifecycleFixtures::fileAnchor(), 'Use Money.', null), $user);
        $otherEvents = [
            LifecycleFixtures::recorded(1, new ReviewStarted('main @ 79f8595a4c1e', RefName::fromString('main'), LifecycleFixtures::sha(LifecycleFixtures::REVIEW_COMMIT)), $user),
            LifecycleFixtures::recorded(3, BatchSubmitted::of(BatchId::generate(), null, [LifecycleFixtures::requestId()], []), $user),
            LifecycleFixtures::recorded(4, new ReviewClosed(null), $user),
        ];

        foreach ($otherEvents as $recorded) {
            self::assertSame([], RequestReducer::affectedRequestIds($recorded));
        }
        self::assertEquals(
            [LifecycleFixtures::REQUEST_ID => RequestReducer::apply(null, $opened)],
            RequestReducer::replay([$otherEvents[0], $opened, $otherEvents[1], $otherEvents[2]]),
        );
        $this->expectException(\LogicException::class);
        RequestReducer::apply(RequestReducer::apply(null, $opened), $otherEvents[1]);
    }

    #[TestDox('commit.found moves a link from pending to available')]
    public function testCommitFoundMovesALinkFromPendingToAvailable(): void
    {
        $user = LifecycleFixtures::user();
        $agent = LifecycleFixtures::agent();
        $otherRequestId = LifecycleFixtures::requestId(LifecycleFixtures::OTHER_REQUEST_ID);
        $bothRequests = [LifecycleFixtures::requestId(), $otherRequestId];
        $log = [
            LifecycleFixtures::recorded(1, new RequestOpened(LifecycleFixtures::fileAnchor(), 'Use Money.', null), $user),
            LifecycleFixtures::recorded(2, new RequestOpened(LifecycleFixtures::fileAnchor(), 'Extract TaxRounding.', null), $user, $otherRequestId),
            LifecycleFixtures::recorded(3, new RequestClaimed(), $agent),
            LifecycleFixtures::recorded(4, new RequestClaimed(), $agent, $otherRequestId),
            LifecycleFixtures::recorded(5, CommitLinked::pending(LifecycleFixtures::sha(), $bothRequests, null), $agent),
        ];
        $found = LifecycleFixtures::recorded(6, CommitFound::of(LifecycleFixtures::sha(), $bothRequests, 'Use Money', new \DateTimeImmutable('2026-09-29T07:52:30Z')), Actor::system());

        $pending = RequestReducer::replay($log);
        $available = RequestReducer::replay([...$log, $found]);

        foreach ([LifecycleFixtures::REQUEST_ID, LifecycleFixtures::OTHER_REQUEST_ID] as $requestId) {
            self::assertEquals([new LinkedCommit(LifecycleFixtures::sha(), CommitLinkState::Pending)], $pending[$requestId]->links);
            self::assertEquals([new LinkedCommit(LifecycleFixtures::sha(), CommitLinkState::Available)], $available[$requestId]->links);
            self::assertSame([6, 5], [$available[$requestId]->lastEventSeq, $available[$requestId]->lastAgentEventSeq]);
        }
    }

    /**
     * @return iterable<string, array{RequestState, RecordedEvent}>
     */
    public static function eventsThatDoNotFit(): iterable
    {
        $agent = LifecycleFixtures::agent();
        $open = self::state(RequestStatus::Open, lastUserEventSeq: 1, lastEventSeq: 5);

        yield 'seq not after the last seq' => [$open, LifecycleFixtures::recorded(5, new RequestClaimed(), $agent)];
        yield 'event of another request' => [$open, LifecycleFixtures::recorded(6, new RequestClaimed(), $agent, LifecycleFixtures::requestId(LifecycleFixtures::OTHER_REQUEST_ID))];
        yield 'second request.opened' => [$open, LifecycleFixtures::recorded(6, new RequestOpened(LifecycleFixtures::fileAnchor(), 'Again.', null), LifecycleFixtures::user())];
        yield 'release outside in_progress' => [$open, LifecycleFixtures::recorded(6, new RequestReleased(null), $agent)];
        yield 'unlink of a commit that is not linked' => [$open, LifecycleFixtures::recorded(6, new CommitUnlinked(LifecycleFixtures::sha(), LifecycleFixtures::requestId()), $agent)];
    }

    #[DataProvider('eventsThatDoNotFit')]
    #[TestDox('Refuses an event out of seq order, of another request, or that does not fit the status or links')]
    public function testRefusesAnEventThatDoesNotFit(RequestState $state, RecordedEvent $recorded): void
    {
        $this->expectException(\LogicException::class);

        RequestReducer::apply($state, $recorded);
    }

    #[TestDox('The first event of a request must be request.opened')]
    public function testTheFirstEventMustBeRequestOpened(): void
    {
        $this->expectException(\LogicException::class);

        RequestReducer::apply(null, LifecycleFixtures::recorded(1, new RequestClaimed(), LifecycleFixtures::agent()));
    }

    /**
     * @param list<LinkedCommit> $links
     */
    private static function inProgress(int $lastAgentEventSeq, array $links, ?int $lastEventSeq = null): RequestState
    {
        return self::state(
            RequestStatus::InProgress,
            statusBeforeClaim: RequestStatus::Open,
            claimedBy: LifecycleFixtures::agent(),
            claimedAt: new \DateTimeImmutable(LifecycleFixtures::CLAIMED_AT),
            links: $links,
            lastUserEventSeq: 1,
            lastAgentEventSeq: $lastAgentEventSeq,
            lastEventSeq: $lastEventSeq,
        );
    }

    /**
     * @param list<LinkedCommit> $links
     */
    private static function state(
        RequestStatus $status,
        ?RequestStatus $statusBeforeClaim = null,
        ?Actor $claimedBy = null,
        ?\DateTimeImmutable $claimedAt = null,
        ?ActorKind $wontDoSetBy = null,
        ?RequestStatus $verifiedFrom = null,
        array $links = [],
        ?int $lastUserEventSeq = null,
        ?int $lastAgentEventSeq = null,
        bool $lastAgentEventAsksQuestion = false,
        ?int $lastEventSeq = null,
    ): RequestState {
        return RequestState::fromParts(
            LifecycleFixtures::requestId(),
            LifecycleFixtures::reviewId(),
            $status,
            $statusBeforeClaim,
            $claimedBy,
            $claimedAt,
            $wontDoSetBy,
            $verifiedFrom,
            $links,
            $lastUserEventSeq,
            $lastAgentEventSeq,
            $lastAgentEventAsksQuestion,
            $lastEventSeq,
        );
    }
}
