<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Review;

use App\Domain\Actor\Actor;
use App\Domain\Error\ReviewClosed;
use App\Domain\Identity\CommentId;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\Open;
use App\Domain\Request\Command\Release;
use App\Domain\Request\Command\Reopen;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\RequestCommand;
use App\Domain\Request\Command\UnlinkCommit;
use App\Domain\Request\Command\Verify;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\LinkedCommit;
use App\Domain\Request\RequestDecider;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;
use App\Domain\Review\Command\CloseReview;
use App\Domain\Review\ReviewDecider;
use App\Tests\Unit\Domain\Request\CatchesDomainErrors;
use App\Tests\Unit\Domain\Request\LifecycleFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ClosedReviewTest extends KernelTestCase
{
    use CatchesDomainErrors;

    /**
     * Each write with valid input on a state that allows it on an open review, and its event count there.
     *
     * @return iterable<string, array{RequestCommand, list<RequestState>, Actor, int}>
     */
    public static function writes(): iterable
    {
        $user = LifecycleFixtures::user();
        $agent = LifecycleFixtures::agent();
        $requestId = LifecycleFixtures::requestId();
        $open = [LifecycleFixtures::state(RequestStatus::Open)];
        $claimed = [LifecycleFixtures::state(RequestStatus::InProgress)];
        $claimedWithLink = [LifecycleFixtures::state(RequestStatus::InProgress, links: [new LinkedCommit(LifecycleFixtures::sha(), CommitLinkState::Available)])];
        $reason = 'The float is rounded once at the end, so the totals are exact.';

        yield 'open by the user' => [new Open($requestId, LifecycleFixtures::fileAnchor(), 'Use Money.'), [], $user, 1];
        yield 'reply by the user' => [new Reply($requestId, CommentId::generate(), 'Also the tax.'), $open, $user, 1];
        yield 'claim by an agent' => [new Claim($requestId), $open, $agent, 1];
        yield 'repeated claim by the holder' => [new Claim($requestId), $claimed, $agent, 0];
        yield 'release by the holder' => [new Release($requestId), $claimed, $agent, 1];
        yield 'link_commits by the holder' => [new LinkCommits(LifecycleFixtures::sha(), [$requestId]), $claimed, $agent, 1];
        yield 'unlink_commit by the holder' => [new UnlinkCommit($requestId, LifecycleFixtures::sha()), $claimedWithLink, $agent, 1];
        yield 'mark_ready_for_review by the holder' => [new MarkReadyForReview($requestId, 'Done.'), $claimedWithLink, $agent, 1];
        yield 'mark_wont_do by an agent' => [new MarkWontDo($requestId, $reason), $open, $agent, 1];
        yield 'verify by the user' => [new Verify($requestId), [LifecycleFixtures::state(RequestStatus::ReadyForReview)], $user, 1];
        yield 'reopen by the user' => [new Reopen($requestId, 'Still wrong.'), [LifecycleFixtures::state(RequestStatus::Verified)], $user, 1];
    }

    /**
     * @param list<RequestState> $states
     */
    #[DataProvider('writes')]
    #[TestDox('Every write by user or agent on a closed review gives review_closed')]
    public function testEveryWriteOnAClosedReviewGivesReviewClosed(RequestCommand $command, array $states, Actor $actor, int $eventCountOnOpenReview): void
    {
        $closedReview = LifecycleFixtures::closedReview();

        self::assertCount($eventCountOnOpenReview, RequestDecider::decide($command, $states, LifecycleFixtures::openReview(), $actor));
        $error = self::domainError(ReviewClosed::class, static fn (): array => RequestDecider::decide($command, $states, $closedReview, $actor));
        self::assertEquals(['reviewId' => $closedReview->reviewId, 'closedAt' => $closedReview->closedAt], $error->details());
    }

    #[TestDox('Closing a closed review gives review_closed')]
    public function testClosingAClosedReviewGivesReviewClosed(): void
    {
        $closedReview = LifecycleFixtures::closedReview();

        $error = self::domainError(ReviewClosed::class, static fn (): array => ReviewDecider::decide(new CloseReview('Again.'), $closedReview, LifecycleFixtures::user()));

        self::assertEquals(['reviewId' => $closedReview->reviewId, 'closedAt' => $closedReview->closedAt], $error->details());
    }
}
