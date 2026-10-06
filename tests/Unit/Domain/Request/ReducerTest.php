<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Event\BatchSubmittedEvent;
use App\Domain\Event\CommitFoundEvent;
use App\Domain\Event\DecidedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\BatchId;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\Open;
use App\Domain\Request\Command\Release;
use App\Domain\Request\Command\Reopen;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\UnlinkCommit;
use App\Domain\Request\Command\Verify;
use App\Domain\Request\RequestReducer;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;
use App\Domain\Review\ReviewSnapshot;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReducerTest extends KernelTestCase
{
    private const string LINKED = RequestScenario::LINKED_COMMIT;
    private const string OTHER = RequestScenario::OTHER_COMMIT;

    #[TestDox('Every event type sets its state fields, and batch.submitted changes none')]
    public function testEveryEventTypeUpdatesTheState(): void
    {
        $scenario = new RequestScenario();
        $user = RequestScenario::user();
        $agent = RequestScenario::claimingAgent();
        $started = ['title' => 'main @ 79f8595a4c1e', 'ref' => 'main', 'commitSha' => '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091', 'startedBy' => 'user 01K6AZZ9Y8X7W6V5T4S3R2Q1P0', 'createdAt' => '07:01'];
        self::assertSame([...$started, 'closedAt' => null, 'closedBy' => null, 'closeNote' => null], self::reviewFields($scenario->review()), 'review.started');
        $id = $scenario->open();

        $state = $scenario->state();
        self::assertTrue($id->equals($state->id));
        self::assertTrue($scenario->review()->id->equals($state->reviewId));
        self::assertEquals($scenario->anchor(), $state->anchor);
        self::assertSame('Use `Money` instead of `float` for totals.', $state->body);
        self::assertEquals($user, $state->author);
        self::assertSame('07:02', $state->createdAt->format('H:i'));

        $expected = [
            'status' => 'open', 'statusBeforeClaim' => null, 'claimedBy' => null, 'claimedAt' => null, 'wontDoBy' => null,
            'resolution' => null, 'links' => [], 'lastUserCommentSeq' => null, 'lastAgentEventSeq' => null,
            'lastAgentEventIsQuestion' => false, 'lastEventSeq' => 2, 'updatedAt' => '07:02',
        ];
        self::assertSame($expected, self::fields($scenario->state()));

        $steps = [
            'request.commented by the user' => [
                static fn () => $scenario->run(new Reply($id, 'Keep the old method.'), $user),
                ['lastUserCommentSeq' => 3],
            ],
            'request.claimed' => [
                static fn () => $scenario->run(new Claim($id), $agent),
                ['status' => 'in_progress', 'statusBeforeClaim' => 'open', 'claimedBy' => 'claude-code laptop', 'claimedAt' => '07:04', 'lastAgentEventSeq' => 4],
            ],
            'request.commented with a question' => [
                static fn () => $scenario->run(new Reply($id, 'Shipping too?', question: true), $agent),
                ['lastAgentEventSeq' => 5, 'lastAgentEventIsQuestion' => true],
            ],
            'commit.linked while pending' => [
                static fn () => $scenario->run(new LinkCommits(CommitSha::fromString(self::OTHER), [$id]), $agent),
                ['links' => [self::OTHER => 'pending'], 'lastAgentEventSeq' => 6, 'lastAgentEventIsQuestion' => false],
            ],
            'commit.found' => [
                static fn () => $scenario->record(self::found(self::OTHER, $scenario), Actor::system()),
                ['links' => [self::OTHER => 'available']],
            ],
            'commit.unlinked' => [
                static fn () => $scenario->run(new UnlinkCommit($id, CommitSha::fromString(self::OTHER)), $agent),
                ['links' => [], 'lastAgentEventSeq' => 8],
            ],
            'commit.linked while available' => [
                static fn () => $scenario->run(new LinkCommits(CommitSha::fromString(self::LINKED), [$id]), $agent, [RequestScenario::commit(self::LINKED)]),
                ['links' => [self::LINKED => 'available'], 'lastAgentEventSeq' => 9],
            ],
            'request.released' => [
                static fn () => $scenario->run(new Release($id), $agent),
                ['status' => 'open', 'statusBeforeClaim' => null, 'claimedBy' => null, 'claimedAt' => null, 'lastAgentEventSeq' => 10],
            ],
            'request.wont_do' => [
                static fn () => $scenario->run(new MarkWontDo($id, RequestScenario::REASON), $agent),
                ['status' => 'wont_do', 'wontDoBy' => 'agent', 'resolution' => 'wont_do', 'lastAgentEventSeq' => 11],
            ],
            'request.reopened' => [
                static fn () => $scenario->run(new Reopen($id, 'Please do it.'), $user),
                ['status' => 'reopened', 'wontDoBy' => null, 'resolution' => null],
            ],
            'request.claimed from reopened' => [
                static fn () => $scenario->run(new Claim($id), $agent),
                ['status' => 'in_progress', 'statusBeforeClaim' => 'reopened', 'claimedBy' => 'claude-code laptop', 'claimedAt' => '07:13', 'lastAgentEventSeq' => 13],
            ],
            'request.ready_for_review' => [
                static fn () => $scenario->run(new MarkReadyForReview($id, 'Done.'), $agent),
                ['status' => 'ready_for_review', 'statusBeforeClaim' => null, 'claimedBy' => null, 'claimedAt' => null, 'resolution' => 'fixed', 'lastAgentEventSeq' => 14],
            ],
            'request.verified' => [
                static fn () => $scenario->run(new Verify($id), $user),
                ['status' => 'verified'],
            ],
        ];

        foreach ($steps as $step => [$run, $changes]) {
            $run();
            $seq = $scenario->lastSeq();
            $expected = [...$expected, ...$changes, 'lastEventSeq' => $seq, 'updatedAt' => sprintf('07:%02d', $seq)];
            self::assertSame($expected, self::fields($scenario->state()), $step);
        }

        $verified = $scenario->state();
        $batch = $scenario->record(new DecidedEvent(new BatchSubmittedEvent(BatchId::generate(), null, [$id], [])), $user);
        $scenario->closeReview();
        self::assertSame($verified, RequestReducer::apply($verified, $batch), 'batch.submitted');
        self::assertSame($verified, $scenario->state(), 'review.closed');
        $closed = ['closedAt' => sprintf('07:%02d', $scenario->lastSeq()), 'closedBy' => 'user 01K6AZZ9Y8X7W6V5T4S3R2Q1P0', 'closeNote' => 'Shipped in 2.4'];
        self::assertSame([...$started, ...$closed], self::reviewFields($scenario->review()), 'review.closed');
    }

    #[TestDox('A general request opens without an anchor')]
    public function testGeneralRequestOpensWithoutAnAnchor(): void
    {
        $scenario = new RequestScenario();

        $scenario->run(new Open(null, 'Add a full-screen focus mode.'), RequestScenario::user());

        self::assertNull($scenario->state()->anchor);
        self::assertSame(RequestStatus::Open, $scenario->state()->status);
    }

    #[TestDox('commit.found moves a link from pending to available')]
    public function testCommitFoundMakesTheLinkAvailable(): void
    {
        $scenario = RequestScenario::inState('in_progress', linked: false);
        $agent = RequestScenario::claimingAgent();
        $scenario->run(new LinkCommits(CommitSha::fromString(self::LINKED), [$scenario->requestId()]), $agent);
        $scenario->run(new LinkCommits(CommitSha::fromString(self::OTHER), [$scenario->requestId()]), $agent);

        $scenario->record(self::found(self::OTHER, $scenario), Actor::system());

        self::assertSame([self::LINKED => 'pending', self::OTHER => 'available'], self::fields($scenario->state())['links']);
        self::assertSame(RequestStatus::InProgress, $scenario->state()->status);
    }

    private static function found(string $sha, RequestScenario $scenario): DecidedEvent
    {
        $commit = RequestScenario::commit($sha);

        return new DecidedEvent(new CommitFoundEvent($commit->commitSha, [$scenario->requestId()], $commit->subject, $commit->committedAt));
    }

    /**
     * @return array<string, string|null>
     */
    private static function reviewFields(ReviewSnapshot $review): array
    {
        return [
            'title' => $review->title,
            'ref' => $review->ref->toString(),
            'commitSha' => $review->commitSha->toString(),
            'startedBy' => "{$review->startedBy->kind->value} {$review->startedBy->userId}",
            'createdAt' => $review->createdAt->format('H:i'),
            'closedAt' => $review->closedAt?->format('H:i'),
            'closedBy' => null === $review->closedBy ? null : "{$review->closedBy->kind->value} {$review->closedBy->userId}",
            'closeNote' => $review->closeNote,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fields(RequestState $state): array
    {
        $links = [];

        foreach ($state->links as $link) {
            $links[$link->commitSha->toString()] = $link->state->value;
        }

        return [
            'status' => $state->status->value,
            'statusBeforeClaim' => $state->statusBeforeClaim?->value,
            'claimedBy' => $state->claimedBy?->agentName,
            'claimedAt' => $state->claimedAt?->format('H:i'),
            'wontDoBy' => $state->wontDoBy?->value,
            'resolution' => $state->resolution?->value,
            'links' => $links,
            'lastUserCommentSeq' => $state->lastUserCommentSeq,
            'lastAgentEventSeq' => $state->lastAgentEventSeq,
            'lastAgentEventIsQuestion' => $state->lastAgentEventIsQuestion,
            'lastEventSeq' => $state->lastEventSeq,
            'updatedAt' => $state->updatedAt->format('H:i'),
        ];
    }
}
