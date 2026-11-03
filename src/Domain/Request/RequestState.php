<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Anchor\Anchor;
use App\Domain\Event\Codec\AnchorCodec;
use App\Domain\Event\Codec\CorruptPayload;
use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Event\Codec\TimeCodec;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\UserId;
use App\Domain\Repository\RepositorySource;
use App\Domain\Review\Delivery;

/**
 * One request as {@see RequestReducer} folds it from the request's thread. {@see AwaitingPolicy} derives `awaiting`.
 */
final readonly class RequestState
{
    /**
     * @param list<LinkedCommit>   $links           In link order
     * @param list<PullRequestUrl> $pullRequestUrls In link order
     */
    public function __construct(
        public RequestId $id,
        public ReviewId $reviewId,
        public ?Anchor $anchor,
        public string $body,
        public Actor $author,
        public \DateTimeImmutable $createdAt,
        public RequestStatus $status,
        public ?RequestStatus $statusBeforeClaim,
        public ?Actor $claimedBy,
        public ?\DateTimeImmutable $claimedAt,
        public ?ActorKind $wontDoBy,
        public ?Resolution $resolution,
        public array $links,
        public array $pullRequestUrls,
        public ?int $lastUserCommentSeq,
        public ?int $lastAgentEventSeq,
        public bool $lastAgentEventIsQuestion,
        public int $lastEventSeq,
        public \DateTimeImmutable $updatedAt,
        public ?CarriedFrom $carriedFrom = null,
        public bool $requireApproval = false,
        public ?Proposal $proposal = null,
        public ?SuggestionState $suggestion = null,
        public ?string $title = null,
        public RequestType $type = RequestType::Change,
        public ?string $group = null,
    ) {
        $this->assertConsistent();
    }

    /**
     * @param array{status?: RequestStatus, statusBeforeClaim?: ?RequestStatus, claimedBy?: ?Actor, claimedAt?: ?\DateTimeImmutable, wontDoBy?: ?ActorKind, resolution?: ?Resolution, links?: list<LinkedCommit>, pullRequestUrls?: list<PullRequestUrl>, lastUserCommentSeq?: ?int, lastAgentEventSeq?: ?int, lastAgentEventIsQuestion?: bool, lastEventSeq?: int, updatedAt?: \DateTimeImmutable, requireApproval?: bool, proposal?: ?Proposal, suggestion?: ?SuggestionState, title?: string} $changes
     */
    public function with(array $changes): self
    {
        $state = clone ($this, $changes);
        $state->assertConsistent();

        return $state;
    }

    /**
     * The value of `change_request.state` (D98). The row holds `id` and `reviewId`.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'anchor' => null === $this->anchor ? null : AnchorCodec::encode($this->anchor),
            'title' => $this->title,
            'type' => $this->type->value,
            'group' => $this->group,
            'body' => $this->body,
            'author' => self::actorPayload($this->author),
            'createdAt' => TimeCodec::encode($this->createdAt),
            'status' => $this->status->value,
            'statusBeforeClaim' => $this->statusBeforeClaim?->value,
            'claimedBy' => null === $this->claimedBy ? null : self::actorPayload($this->claimedBy),
            'claimedAt' => null === $this->claimedAt ? null : TimeCodec::encode($this->claimedAt),
            'wontDoBy' => $this->wontDoBy?->value,
            'resolution' => $this->resolution?->value,
            'links' => array_map(static fn (LinkedCommit $link): array => ['commitSha' => $link->commitSha->toString(), 'state' => $link->state->value], $this->links),
            'pullRequestUrls' => array_map(static fn (PullRequestUrl $url): string => $url->toString(), $this->pullRequestUrls),
            'lastUserCommentSeq' => $this->lastUserCommentSeq,
            'lastAgentEventSeq' => $this->lastAgentEventSeq,
            'lastAgentEventIsQuestion' => $this->lastAgentEventIsQuestion,
            'lastEventSeq' => $this->lastEventSeq,
            'updatedAt' => TimeCodec::encode($this->updatedAt),
            'carriedFrom' => null === $this->carriedFrom ? null : ['reviewId' => $this->carriedFrom->reviewId->toString(), 'requestId' => $this->carriedFrom->requestId->toString()],
            'requireApproval' => $this->requireApproval,
            'proposal' => null === $this->proposal ? null : ['eventId' => $this->proposal->eventId->toString(), 'state' => $this->proposal->state->value, 'pullRequest' => $this->proposal->pullRequest],
            'suggestion' => $this->suggestion?->value,
        ];
    }

    /**
     * @param array<mixed> $payload Of {@see self::toPayload()}
     *
     * @throws CorruptPayload
     */
    public static function fromPayload(RequestId $id, ReviewId $reviewId, array $payload): self
    {
        $reader = PayloadReader::of($payload);
        $anchor = $reader->nullableObject('anchor');
        $claimedBy = $reader->nullableObject('claimedBy');
        $carriedFrom = $reader->nullableObject('carriedFrom');
        $proposal = $reader->nullableObject('proposal');

        return new self(
            id: $id,
            reviewId: $reviewId,
            anchor: null === $anchor ? null : AnchorCodec::decode($anchor),
            body: $reader->string('body'),
            author: self::actorOf($reader->object('author')),
            createdAt: $reader->dateTime('createdAt'),
            status: $reader->enum('status', RequestStatus::class),
            statusBeforeClaim: $reader->nullableEnum('statusBeforeClaim', RequestStatus::class),
            claimedBy: null === $claimedBy ? null : self::actorOf($claimedBy),
            claimedAt: $reader->nullableDateTime('claimedAt'),
            wontDoBy: $reader->nullableEnum('wontDoBy', ActorKind::class),
            resolution: $reader->nullableEnum('resolution', Resolution::class),
            links: array_map(static fn (PayloadReader $link): LinkedCommit => new LinkedCommit(
                $link->build(static fn (): CommitSha => CommitSha::fromString($link->string('commitSha'))),
                $link->enum('state', CommitLinkState::class),
            ), $reader->objectList('links')),
            pullRequestUrls: array_map(static fn (string $url): PullRequestUrl => $reader->build(static fn (): PullRequestUrl => PullRequestUrl::fromString($url)), $reader->stringList('pullRequestUrls')),
            lastUserCommentSeq: $reader->nullableInt('lastUserCommentSeq'),
            lastAgentEventSeq: $reader->nullableInt('lastAgentEventSeq'),
            lastAgentEventIsQuestion: $reader->bool('lastAgentEventIsQuestion'),
            lastEventSeq: $reader->int('lastEventSeq'),
            updatedAt: $reader->dateTime('updatedAt'),
            carriedFrom: null === $carriedFrom ? null : new CarriedFrom($carriedFrom->id('reviewId', ReviewId::class), $carriedFrom->id('requestId', RequestId::class)),
            requireApproval: $reader->bool('requireApproval'),
            proposal: null === $proposal ? null : new Proposal($proposal->id('eventId', EventId::class), $proposal->enum('state', ProposalState::class), $proposal->nullableBool('pullRequest')),
            suggestion: $reader->nullableEnum('suggestion', SuggestionState::class),
            title: $reader->nullableString('title'),
            type: $reader->nullableEnum('type', RequestType::class) ?? RequestType::Change,
            group: $reader->nullableString('group'),
        );
    }

    public function closed(): bool
    {
        return $this->status->isClosed($this->wontDoBy);
    }

    public function isClaimedBy(Actor $actor): bool
    {
        return null !== $this->claimedBy && $this->claimedBy->equals($actor);
    }

    public function link(CommitSha $commitSha): ?LinkedCommit
    {
        foreach ($this->links as $link) {
            if ($link->commitSha->equals($commitSha)) {
                return $link;
            }
        }

        return null;
    }

    public function hasPullRequest(PullRequestUrl $url): bool
    {
        return array_any($this->pullRequestUrls, static fn (PullRequestUrl $linked): bool => $linked->equals($url));
    }

    /**
     * The linked URL of the pull request that `$url` names, see {@see PullRequestUrl::namesSamePullRequest()}.
     */
    public function linkedPullRequest(PullRequestUrl $url, RepositorySource $source): ?PullRequestUrl
    {
        return array_find($this->pullRequestUrls, static fn (PullRequestUrl $linked): bool => $linked->namesSamePullRequest($url, $source));
    }

    /**
     * Gates the holder's link_commits, mark_ready_for_review, and link_pull_request in {@see AllowedActions}.
     */
    public function proposalGateOpen(): bool
    {
        return null === $this->proposal ? !$this->requireApproval : ProposalState::Approved === $this->proposal->state;
    }

    /**
     * The workspace of the review, and the pull request choice of the approved proposal before that of the review.
     */
    public function delivery(Delivery $review): Delivery
    {
        $approved = ProposalState::Approved === $this->proposal?->state ? $this->proposal->pullRequest : null;

        return new Delivery($review->workspace, $approved ?? $review->pullRequest);
    }

    public function suggestionPending(): bool
    {
        return SuggestionState::Pending === $this->suggestion;
    }

    /**
     * @return array{kind: string, userId: string|null, agentName: string|null}
     */
    private static function actorPayload(Actor $actor): array
    {
        return ['kind' => $actor->kind->value, 'userId' => $actor->userId?->toString(), 'agentName' => $actor->agentName];
    }

    private static function actorOf(PayloadReader $actor): Actor
    {
        $kind = $actor->enum('kind', ActorKind::class);
        $userId = $actor->nullableId('userId', UserId::class);
        $agentName = $actor->nullableString('agentName');

        return $actor->build(static fn (): Actor => Actor::of($kind, $userId, $agentName));
    }

    private function assertConsistent(): void
    {
        $inProgress = RequestStatus::InProgress === $this->status;

        if ($inProgress !== (null !== $this->claimedBy) || $inProgress !== (null !== $this->claimedAt) || $inProgress !== (null !== $this->statusBeforeClaim)) {
            throw new \LogicException('claimedBy, claimedAt, and statusBeforeClaim are set exactly while the request is in_progress.');
        }

        if ((RequestStatus::WontDo === $this->status) !== (null !== $this->wontDoBy)) {
            throw new \LogicException('wontDoBy is set exactly while the request is wont_do.');
        }

        $resolutionFits = match ($this->status) {
            RequestStatus::Open, RequestStatus::InProgress, RequestStatus::Reopened => null === $this->resolution,
            RequestStatus::ReadyForReview => Resolution::Fixed === $this->resolution,
            RequestStatus::WontDo => Resolution::WontDo === $this->resolution,
            RequestStatus::Verified => null !== $this->resolution,
        };

        if (!$resolutionFits) {
            throw new \LogicException("The resolution does not fit the status {$this->status->value}.");
        }

        if ((ActorKind::User === $this->author->kind) !== (null === $this->suggestion)) {
            throw new \LogicException('suggestion is null exactly when a user is the author.');
        }

        $suggestionFits = match ($this->suggestion) {
            null, SuggestionState::Accepted => true,
            SuggestionState::Pending => RequestStatus::Open === $this->status,
            SuggestionState::Dismissed => RequestStatus::WontDo === $this->status && ActorKind::User === $this->wontDoBy,
        };

        if (!$suggestionFits) {
            throw new \LogicException("A {$this->suggestion?->value} suggestion does not fit the status {$this->status->value}.");
        }

        if (null === $this->proposal) {
            return;
        }

        if ($this->proposal->isPending() ? !$inProgress : !in_array($this->status, [RequestStatus::InProgress, RequestStatus::ReadyForReview, RequestStatus::Verified], true)) {
            throw new \LogicException("A {$this->proposal->state->value} proposal does not fit the status {$this->status->value}.");
        }
    }
}
