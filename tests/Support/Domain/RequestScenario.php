<?php

declare(strict_types=1);

namespace App\Tests\Support\Domain;

use App\Domain\Actor\Actor;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Error\DomainError;
use App\Domain\Event\CommitFoundEvent;
use App\Domain\Event\CommitLinkedEvent;
use App\Domain\Event\CommitUnlinkedEvent;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\PullRequestLinkedEvent;
use App\Domain\Event\PullRequestUnlinkedEvent;
use App\Domain\Event\RecordedEvent;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitInfo;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use App\Domain\Repository\RepositoryName;
use App\Domain\Request\Command\ApproveProposal;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\LinkPullRequest;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\Open;
use App\Domain\Request\Command\ProposeSolution;
use App\Domain\Request\Command\RejectProposal;
use App\Domain\Request\Command\Release;
use App\Domain\Request\Command\Reopen;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\RequestCommand;
use App\Domain\Request\Command\SetPlanRequirement;
use App\Domain\Request\Command\UnlinkCommit;
use App\Domain\Request\Command\UnlinkPullRequest;
use App\Domain\Request\Command\Verify;
use App\Domain\Request\RequestDecider;
use App\Domain\Request\RequestReducer;
use App\Domain\Request\RequestState;
use App\Domain\Review\Command\CloseReview;
use App\Domain\Review\Command\StartReview;
use App\Domain\Review\ReviewDecider;
use App\Domain\Review\ReviewReducer;
use App\Domain\Review\ReviewSnapshot;

/**
 * Runs commands through the deciders and folds the events through the reducers, like the event store does.
 */
final class RequestScenario
{
    /**
     * The states that the transition matrix covers, in its column order. The "proposal" states are in_progress with a
     * proposal. The "plan" states are in_progress with requireApproval: without a proposal after a reopen, or approved.
     */
    public const array STATES = [
        'open', 'in_progress', 'ready_for_review', 'verified', 'reopened', 'wont_do by agent', 'wont_do by user',
        'proposal pending', 'proposal rejected', 'plan required', 'plan approved',
    ];
    public const string LINKED_COMMIT = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    public const string OTHER_COMMIT = 'e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e';
    public const string REASON = 'The current rounding is required by the tax office.';
    public const string REPOSITORY = 'acme/billing';
    public const string PULL_REQUEST = 'https://github.com/acme/billing/pull/42';
    public const string LINKED_PULL_REQUEST = 'https://github.com/acme/billing/pull/41';
    private const string USER_ID = '01K6AZZ9Y8X7W6V5T4S3R2Q1P0';
    private const string REVIEW_COMMIT = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091';
    private const string PLAN = 'Extract `TaxRounding` and call it from `total()` and `tax()`.';
    private const string DIFF = "diff --git a/src/Billing/InvoiceTotals.php b/src/Billing/InvoiceTotals.php\n--- a/src/Billing/InvoiceTotals.php\n+++ b/src/Billing/InvoiceTotals.php\n@@ -40 +40 @@\n-        \$sum = 0.0;\n+        \$sum = Money::zero();\n";

    private readonly TenantId $tenantId;
    private readonly ReviewId $reviewId;
    private ReviewSnapshot $review;
    private int $seq = 0;
    /** @var array<string, RequestState> */
    private array $states = [];
    private ?RequestId $current = null;

    public function __construct()
    {
        $this->tenantId = TenantId::generate();
        $this->reviewId = ReviewId::generate();
        $start = new StartReview('main @ 79f8595a4c1e', RefName::fromString('main'), CommitSha::fromString(self::REVIEW_COMMIT));
        [$started] = ReviewDecider::decide($start, null, self::user());
        $this->review = ReviewReducer::apply(null, $this->recorded($started, self::user()));
    }

    /**
     * A request in one of {@see self::STATES}. The claiming agent did the agent steps. With $linked, the request has one available
     * commit link and one pull request link.
     */
    public static function inState(string $state, bool $linked = true): self
    {
        $scenario = new self();
        $planRequired = str_starts_with($state, 'plan ');
        $id = $scenario->open($planRequired);
        $agent = self::claimingAgent();

        $scenario->run(new Claim($id), $agent);

        if ($planRequired) {
            $scenario->propose();
            $scenario->run($scenario->validCommand('approve_proposal'), self::user());
        }

        if ($linked) {
            $scenario->run(new LinkCommits(CommitSha::fromString(self::LINKED_COMMIT), [$id]), $agent, [self::commit(self::LINKED_COMMIT)]);
            $scenario->run(new LinkPullRequest(self::LINKED_PULL_REQUEST, [$id], RepositoryName::github(self::REPOSITORY)), $agent);
        }

        match ($state) {
            'open' => $scenario->run(new Release($id), $agent),
            'in_progress', 'plan approved' => [],
            'ready_for_review', 'verified', 'reopened' => $scenario->run(new MarkReadyForReview($id, 'Extracted TaxRounding.'), $agent),
            'wont_do by agent' => $scenario->run(new MarkWontDo($id, self::REASON), $agent),
            'wont_do by user' => $scenario->run(new MarkWontDo($id, self::REASON), self::user()),
            'proposal pending' => $scenario->propose(),
            'proposal rejected' => [$scenario->propose(), $scenario->run($scenario->validCommand('reject_proposal'), self::user())],
            'plan required' => [
                $scenario->run(new MarkReadyForReview($id, 'Extracted TaxRounding.'), $agent),
                $scenario->run(new Reopen($id, 'Also cover the shipping totals.'), self::user()),
                $scenario->run(new Claim($id), $agent),
            ],
            default => throw new \LogicException("Unknown state {$state}."),
        };

        match ($state) {
            'verified' => $scenario->run(new Verify($id), self::user()),
            'reopened' => $scenario->run(new Reopen($id, 'Also cover the shipping totals.'), self::user()),
            default => [],
        };

        return $scenario;
    }

    public static function user(): Actor
    {
        return Actor::user(UserId::fromString(self::USER_ID));
    }

    public static function claimingAgent(): Actor
    {
        return Actor::agent(UserId::fromString(self::USER_ID), 'claude-code laptop');
    }

    public static function otherAgent(): Actor
    {
        return Actor::agent(UserId::fromString(self::USER_ID), 'codex desktop');
    }

    public static function caller(string $name): Actor
    {
        return match ($name) {
            'user' => self::user(),
            'claiming agent' => self::claimingAgent(),
            'another agent' => self::otherAgent(),
            default => throw new \LogicException("Unknown caller {$name}."),
        };
    }

    public static function commit(string $sha): CommitInfo
    {
        return new CommitInfo(CommitSha::fromString($sha), 'Use Money for invoice totals', new \DateTimeImmutable('2026-09-29T07:44:10Z'));
    }

    public function anchor(): Anchor
    {
        return Anchor::of(
            AnchorKind::File,
            RepoPath::fromString('src/Billing/InvoiceTotals.php'),
            $this->review->commitSha,
            BlobSha::fromString('3b18e512dba79e4c8300dd08aeb37f8e728b8dad'),
            null,
            null,
            null,
        );
    }

    /**
     * A command with valid input for the current request, named by its action, or "open". set_plan_requirement switches
     * the current value.
     */
    public function validCommand(string $command): RequestCommand
    {
        if ('open' === $command) {
            return new Open($this->anchor(), 'Use `Money` instead of `float` for totals.');
        }

        $id = $this->requestId();
        $proposalEventId = $this->state()->proposal->eventId ?? EventId::generate();

        return match ($command) {
            'reply' => new Reply($id, 'Should this also cover the shipping totals?'),
            'claim' => new Claim($id),
            'release' => new Release($id),
            'link_commits' => new LinkCommits(CommitSha::fromString(self::OTHER_COMMIT), [$id]),
            'unlink_commit' => new UnlinkCommit($id, CommitSha::fromString(self::LINKED_COMMIT)),
            'link_pull_request' => new LinkPullRequest(self::PULL_REQUEST, [$id], RepositoryName::github(self::REPOSITORY)),
            'unlink_pull_request' => new UnlinkPullRequest($id, self::LINKED_PULL_REQUEST, RepositoryName::github(self::REPOSITORY)),
            'mark_ready_for_review' => new MarkReadyForReview($id, 'Extracted TaxRounding.'),
            'mark_wont_do' => new MarkWontDo($id, self::REASON),
            'verify' => new Verify($id),
            'reopen' => new Reopen($id, 'Also cover the shipping totals.'),
            'propose_solution' => new ProposeSolution($id, self::PLAN, self::DIFF),
            'approve_proposal' => new ApproveProposal($id, $proposalEventId),
            'reject_proposal' => new RejectProposal($id, $proposalEventId, 'Keep the rounding inside `InvoiceTotals`.'),
            'set_plan_requirement' => new SetPlanRequirement($id, !$this->state()->requireApproval),
            default => throw new \LogicException("Unknown command {$command}."),
        };
    }

    public function open(bool $requireApproval = false): RequestId
    {
        $this->run(new Open($this->anchor(), 'Use `Money` instead of `float` for totals.', requireApproval: $requireApproval), self::user());

        return $this->requestId();
    }

    /**
     * The claiming agent proposes for the current request.
     *
     * @return list<DecidedEvent>
     */
    public function propose(): array
    {
        return $this->run($this->validCommand('propose_solution'), self::claimingAgent());
    }

    /**
     * @param list<CommitInfo> $availability
     *
     * @return list<DecidedEvent>
     */
    public function decide(RequestCommand $command, Actor $actor, array $availability = []): array
    {
        $states = array_map(fn (RequestId $id): RequestState => $this->state($id), $command->requestIds());

        return RequestDecider::decide($command, $states, $this->review, $actor, $availability);
    }

    /**
     * @param list<CommitInfo> $availability
     *
     * @return list<DecidedEvent>
     */
    public function run(RequestCommand $command, Actor $actor, array $availability = []): array
    {
        $events = $this->decide($command, $actor, $availability);

        foreach ($events as $event) {
            $this->record($event, $actor);
        }

        return $events;
    }

    /**
     * @param list<CommitInfo> $availability
     *
     * @return string The event types, "no event", an error code, or "LogicException"
     */
    public function outcome(RequestCommand $command, Actor $actor, array $availability = []): string
    {
        try {
            $events = $this->decide($command, $actor, $availability);
        } catch (DomainError $error) {
            return $error->code()->value;
        } catch (\LogicException) {
            return 'LogicException';
        }

        return [] === $events ? 'no event' : implode(', ', array_map(static fn (DecidedEvent $event): string => $event->event::type()->value, $events));
    }

    public function record(DecidedEvent $decided, Actor $actor): RecordedEvent
    {
        $recorded = $this->recorded($decided, $actor);
        $event = $decided->event;
        $requestIds = match (true) {
            $event instanceof CommitLinkedEvent, $event instanceof CommitFoundEvent, $event instanceof PullRequestLinkedEvent => $event->requestIds,
            $event instanceof CommitUnlinkedEvent, $event instanceof PullRequestUnlinkedEvent => [$event->requestId],
            default => null === $decided->requestId ? [] : [$decided->requestId],
        };

        foreach ($requestIds as $requestId) {
            $state = $this->states[$requestId->toString()] ?? null;
            $this->states[$requestId->toString()] = RequestReducer::apply($state, $recorded);
            $this->current = null === $state ? $requestId : $this->current;
        }

        $this->review = ReviewReducer::apply($this->review, $recorded);

        return $recorded;
    }

    public function closeReview(): void
    {
        foreach (ReviewDecider::decide(new CloseReview('Shipped in 2.4'), $this->review, self::user()) as $event) {
            $this->record($event, self::user());
        }
    }

    public function review(): ReviewSnapshot
    {
        return $this->review;
    }

    public function requestId(): RequestId
    {
        return $this->current ?? throw new \LogicException('No request is open.');
    }

    public function state(?RequestId $id = null): RequestState
    {
        return $this->states[($id ?? $this->requestId())->toString()] ?? throw new \LogicException('Unknown request.');
    }

    public function lastSeq(): int
    {
        return $this->seq;
    }

    private function recorded(DecidedEvent $decided, Actor $actor): RecordedEvent
    {
        ++$this->seq;

        return new RecordedEvent(
            EventId::generate(),
            $this->tenantId,
            $this->seq,
            $this->reviewId,
            $decided->requestId,
            $actor,
            new \DateTimeImmutable('2026-09-29T07:00:00Z')->add(new \DateInterval("PT{$this->seq}M")),
            $decided->event,
        );
    }
}
