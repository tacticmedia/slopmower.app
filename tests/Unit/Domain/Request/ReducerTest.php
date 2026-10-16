<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Event\BatchSubmittedEvent;
use App\Domain\Event\CommitFoundEvent;
use App\Domain\Event\DecidedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\BatchId;
use App\Domain\Repository\RepositoryName;
use App\Domain\Request\Command\AcceptSuggestion;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\DismissSuggestion;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\Open;
use App\Domain\Request\Command\Release;
use App\Domain\Request\Command\Reopen;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\SetPlanRequirement;
use App\Domain\Request\Command\SetTitle;
use App\Domain\Request\Command\UnlinkCommit;
use App\Domain\Request\Command\UnlinkPullRequest;
use App\Domain\Request\Command\Verify;
use App\Domain\Request\PullRequestUrl;
use App\Domain\Request\RequestReducer;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;
use App\Domain\Review\ReviewSnapshot;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'resolution' => null, 'links' => [], 'pullRequestUrls' => [], 'lastUserCommentSeq' => null, 'lastAgentEventSeq' => null,
            'lastAgentEventIsQuestion' => false, 'lastEventSeq' => 2, 'updatedAt' => '07:02', 'requireApproval' => false,
            'suggestion' => null, 'title' => null,
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
            'request.plan_required, without user tracking' => [
                static fn () => $scenario->run(new SetPlanRequirement($id, true), $user),
                ['requireApproval' => true],
            ],
            'request.plan_waived, without user tracking' => [
                static fn () => $scenario->run(new SetPlanRequirement($id, false), $user),
                ['requireApproval' => false],
            ],
            'request.claimed from reopened' => [
                static fn () => $scenario->run(new Claim($id), $agent),
                ['status' => 'in_progress', 'statusBeforeClaim' => 'reopened', 'claimedBy' => 'claude-code laptop', 'claimedAt' => '07:15', 'lastAgentEventSeq' => 15],
            ],
            'request.ready_for_review' => [
                static fn () => $scenario->run(new MarkReadyForReview($id, 'Done.'), $agent),
                ['status' => 'ready_for_review', 'statusBeforeClaim' => null, 'claimedBy' => null, 'claimedAt' => null, 'resolution' => 'fixed', 'lastAgentEventSeq' => 16],
            ],
            'pull_request.linked, without agent tracking' => [
                static fn () => $scenario->run($scenario->validCommand('link_pull_request'), $agent),
                ['pullRequestUrls' => [RequestScenario::PULL_REQUEST]],
            ],
            'pull_request.unlinked, without agent tracking' => [
                static fn () => $scenario->run(new UnlinkPullRequest($id, RequestScenario::PULL_REQUEST, RepositoryName::github(RequestScenario::REPOSITORY)), $agent),
                ['pullRequestUrls' => []],
            ],
            'request.retitled, without agent tracking' => [
                static fn () => $scenario->run(new SetTitle($id, 'Use Money for totals'), $agent),
                ['title' => 'Use Money for totals'],
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

    #[TestDox('A proposal is pending, then approved, rejected, or superseded, and a decision is a user message')]
    public function testProposalLifecycle(): void
    {
        $scenario = RequestScenario::inState('in_progress');
        $user = RequestScenario::user();

        $first = self::proposalEventId($scenario);
        self::assertSame([$first, 'pending'], self::proposal($scenario->state()));

        $second = self::proposalEventId($scenario);
        self::assertSame([$second, 'pending'], self::proposal($scenario->state()), 'superseded');

        $scenario->run($scenario->validCommand('reject_proposal'), $user);
        self::assertSame([$second, 'rejected'], self::proposal($scenario->state()));
        self::assertSame($scenario->lastSeq(), $scenario->state()->lastUserCommentSeq);

        $third = self::proposalEventId($scenario);
        $scenario->run($scenario->validCommand('approve_proposal'), $user);
        self::assertSame([$third, 'approved'], self::proposal($scenario->state()));
        self::assertSame($scenario->lastSeq(), $scenario->state()->lastUserCommentSeq);

        $scenario->run(new MarkReadyForReview($scenario->requestId(), 'Done.'), RequestScenario::claimingAgent());
        self::assertSame([$third, 'approved'], self::proposal($scenario->state()), 'ready_for_review');
    }

    /**
     * @param \Closure(RequestScenario): mixed $end
     */
    #[TestDox('$step sets the proposal to null')]
    #[DataProvider('claimEnds')]
    public function testClaimEndClearsTheProposal(string $step, \Closure $end): void
    {
        $scenario = RequestScenario::inState('proposal pending');

        $end($scenario);

        self::assertNull($scenario->state()->proposal);
    }

    /**
     * @return iterable<string, array{string, \Closure(RequestScenario): mixed}>
     */
    public static function claimEnds(): iterable
    {
        $agent = RequestScenario::claimingAgent();

        yield 'release' => ['request.released', static fn (RequestScenario $scenario) => $scenario->run(new Release($scenario->requestId()), $agent)];
        yield 'wont_do' => ['request.wont_do', static fn (RequestScenario $scenario) => $scenario->run(new MarkWontDo($scenario->requestId(), RequestScenario::REASON), $agent)];
        yield 'reopen' => ['request.reopened', static function (RequestScenario $scenario) use ($agent): void {
            $scenario->run($scenario->validCommand('approve_proposal'), RequestScenario::user());
            $scenario->run(new MarkReadyForReview($scenario->requestId(), 'Done.'), $agent);
            $scenario->run(new Reopen($scenario->requestId(), 'Also cover the shipping totals.'), RequestScenario::user());
        }];
    }

    /**
     * @param \Closure(RequestScenario): mixed $steps
     * @param array<string, mixed>             $expected
     */
    #[TestDox('$step sets the suggestion fields')]
    #[DataProvider('suggestionSteps')]
    public function testSuggestionLifecycle(string $step, \Closure $steps, array $expected): void
    {
        $scenario = RequestScenario::inState('suggestion pending');

        $steps($scenario);

        $fields = self::fields($scenario->state());
        self::assertSame($expected, array_intersect_key($fields, $expected));
    }

    /**
     * @return iterable<string, array{string, \Closure(RequestScenario): mixed, array<string, mixed>}>
     */
    public static function suggestionSteps(): iterable
    {
        $user = RequestScenario::user();
        $pending = ['status' => 'open', 'wontDoBy' => null, 'resolution' => null, 'lastUserCommentSeq' => null, 'lastAgentEventSeq' => 2, 'requireApproval' => false, 'suggestion' => 'pending'];
        $dismiss = static fn (RequestScenario $scenario) => $scenario->run(new DismissSuggestion($scenario->requestId(), 'Out of scope.'), $user);

        yield 'suggest' => ['A suggestion by an agent', static fn (RequestScenario $scenario) => null, $pending];
        yield 'accept with requireApproval' => [
            'request.suggestion_accepted with requireApproval',
            static fn (RequestScenario $scenario) => $scenario->run(new AcceptSuggestion($scenario->requestId(), true), $user),
            [...$pending, 'lastUserCommentSeq' => 3, 'requireApproval' => true, 'suggestion' => 'accepted'],
        ];
        yield 'accept without requireApproval' => [
            'request.suggestion_accepted without requireApproval',
            static fn (RequestScenario $scenario) => $scenario->run(new AcceptSuggestion($scenario->requestId()), $user),
            [...$pending, 'lastUserCommentSeq' => 3, 'suggestion' => 'accepted'],
        ];
        yield 'dismiss' => ['request.suggestion_dismissed', $dismiss, [...$pending, 'status' => 'wont_do', 'wontDoBy' => 'user', 'resolution' => 'wont_do', 'suggestion' => 'dismissed']];
        yield 'reopen after dismiss' => [
            'request.reopened after a dismiss',
            static fn (RequestScenario $scenario) => [$dismiss($scenario), $scenario->run(new Reopen($scenario->requestId(), 'Round per line after all.'), $user)],
            [...$pending, 'status' => 'reopened', 'suggestion' => 'accepted'],
        ];
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

    /**
     * The claiming agent proposes. Returns the ID of the request.proposed event.
     */
    private static function proposalEventId(RequestScenario $scenario): string
    {
        $scenario->propose();
        $proposal = $scenario->state()->proposal ?? throw new \LogicException('No proposal.');

        return $proposal->eventId->toString();
    }

    /**
     * @return array{string, string}|null
     */
    private static function proposal(RequestState $state): ?array
    {
        return null === $state->proposal ? null : [$state->proposal->eventId->toString(), $state->proposal->state->value];
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
            'pullRequestUrls' => array_map(static fn (PullRequestUrl $url): string => $url->toString(), $state->pullRequestUrls),
            'lastUserCommentSeq' => $state->lastUserCommentSeq,
            'lastAgentEventSeq' => $state->lastAgentEventSeq,
            'lastAgentEventIsQuestion' => $state->lastAgentEventIsQuestion,
            'lastEventSeq' => $state->lastEventSeq,
            'updatedAt' => $state->updatedAt->format('H:i'),
            'requireApproval' => $state->requireApproval,
            'suggestion' => $state->suggestion?->value,
            'title' => $state->title,
        ];
    }
}
