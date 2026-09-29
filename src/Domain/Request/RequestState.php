<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\Violation;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;

/**
 * The lifecycle state of one request. {@see RequestReducer} builds it from the event log.
 * The transition methods serve the reducer: they apply stored events and do not check the actor.
 */
final readonly class RequestState
{
    /**
     * @param list<LinkedCommit> $links                      in link order
     * @param int|null           $lastUserEventSeq           the last event of this request by the user
     * @param int|null           $lastAgentEventSeq          the last event of this request by an agent
     * @param bool               $lastAgentEventAsksQuestion that event is a request.commented with question true
     */
    private function __construct(
        public RequestId $requestId,
        public ReviewId $reviewId,
        public RequestStatus $status,
        public ?RequestStatus $statusBeforeClaim,
        public ?Actor $claimedBy,
        public ?\DateTimeImmutable $claimedAt,
        public ?ActorKind $wontDoSetBy,
        public ?RequestStatus $verifiedFrom,
        public array $links,
        public ?int $lastUserEventSeq,
        public ?int $lastAgentEventSeq,
        public bool $lastAgentEventAsksQuestion,
        public int $lastEventSeq,
    ) {
    }

    /**
     * Builds a state in any status, for example from a projection. A field that the status does not use must be null.
     *
     * @param list<LinkedCommit> $links
     * @param int|null           $lastEventSeq null gives the highest seq given, at least 1
     */
    public static function fromParts(
        RequestId $requestId,
        ReviewId $reviewId,
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
    ): self {
        $lastEventSeq ??= max(1, $lastUserEventSeq ?? 0, $lastAgentEventSeq ?? 0);
        $violations = [
            ...self::claimViolations($status, $statusBeforeClaim, $claimedBy, $claimedAt),
            ...self::closingViolations($status, $wontDoSetBy, $verifiedFrom),
            ...self::linkViolations($links),
            ...self::seqViolations($lastUserEventSeq, $lastAgentEventSeq, $lastAgentEventAsksQuestion, $lastEventSeq),
        ];
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }

        return new self(
            $requestId,
            $reviewId,
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

    public static function opened(RequestId $requestId, ReviewId $reviewId, int $seq): self
    {
        return self::fromParts($requestId, $reviewId, RequestStatus::Open, lastEventSeq: $seq);
    }

    public function claim(Actor $agent, \DateTimeImmutable $claimedAt): self
    {
        $this->assertStatus('claim', RequestStatus::Open, RequestStatus::Reopened);

        return $this->withLifecycle(RequestStatus::InProgress, statusBeforeClaim: $this->status, claimedBy: $agent, claimedAt: $claimedAt);
    }

    public function release(): self
    {
        return $this->withLifecycle($this->statusBeforeClaim ?? throw $this->cannot('release'));
    }

    public function markReadyForReview(): self
    {
        $this->assertStatus('mark_ready_for_review', RequestStatus::InProgress);

        return $this->withLifecycle(RequestStatus::ReadyForReview);
    }

    public function markWontDo(ActorKind $setBy): self
    {
        $this->assertStatus('mark_wont_do', RequestStatus::Open, RequestStatus::Reopened, RequestStatus::InProgress);

        return $this->withLifecycle(RequestStatus::WontDo, wontDoSetBy: $setBy);
    }

    public function verify(): self
    {
        $this->assertStatus('verify', RequestStatus::ReadyForReview, RequestStatus::WontDo);

        return $this->withLifecycle(RequestStatus::Verified, verifiedFrom: $this->status);
    }

    public function reopen(): self
    {
        $this->assertStatus('reopen', RequestStatus::ReadyForReview, RequestStatus::WontDo, RequestStatus::Verified);

        return $this->withLifecycle(RequestStatus::Reopened);
    }

    public function linkCommit(CommitSha $commitSha, CommitLinkState $state): self
    {
        if (null !== $this->linkOf($commitSha)) {
            throw new \LogicException(\sprintf('Commit %s is already linked to request %s.', $commitSha, $this->requestId));
        }

        return $this->withLinks([...$this->links, new LinkedCommit($commitSha, $state)]);
    }

    public function unlinkCommit(CommitSha $commitSha): self
    {
        $this->assertLinked($commitSha);

        return $this->withLinks(array_values(array_filter(
            $this->links,
            static fn (LinkedCommit $link): bool => !$link->commitSha->equals($commitSha),
        )));
    }

    public function markCommitAvailable(CommitSha $commitSha): self
    {
        $this->assertLinked($commitSha);

        return $this->withLinks(array_map(
            static fn (LinkedCommit $link): LinkedCommit => $link->commitSha->equals($commitSha) ? new LinkedCommit($commitSha, CommitLinkState::Available) : $link,
            $this->links,
        ));
    }

    /**
     * Updates the inputs of awaiting rules 2 and 3 and the last seq.
     *
     * @param bool $asksQuestion request.commented with question true
     */
    public function recordEvent(ActorKind $actorKind, int $seq, bool $asksQuestion): self
    {
        if ($seq < $this->lastEventSeq) {
            throw new \LogicException(\sprintf('Seq %d comes before seq %d of request %s.', $seq, $this->lastEventSeq, $this->requestId));
        }
        $byAgent = ActorKind::Agent === $actorKind;

        return self::fromParts(
            $this->requestId,
            $this->reviewId,
            $this->status,
            $this->statusBeforeClaim,
            $this->claimedBy,
            $this->claimedAt,
            $this->wontDoSetBy,
            $this->verifiedFrom,
            $this->links,
            ActorKind::User === $actorKind ? $seq : $this->lastUserEventSeq,
            $byAgent ? $seq : $this->lastAgentEventSeq,
            $byAgent ? $asksQuestion : $this->lastAgentEventAsksQuestion,
            $seq,
        );
    }

    public function linkOf(CommitSha $commitSha): ?LinkedCommit
    {
        return array_find($this->links, static fn (LinkedCommit $link): bool => $link->commitSha->equals($commitSha));
    }

    /**
     * Only an agent holds a claim, and only while the status is in_progress.
     */
    public function isClaimedBy(Actor $actor): bool
    {
        return true === $this->claimedBy?->equals($actor);
    }

    public function isClosed(): bool
    {
        return RequestStatus::Verified === $this->status || ActorKind::User === $this->wontDoSetBy;
    }

    public function resolution(): ?Resolution
    {
        return match ($this->status) {
            RequestStatus::Open, RequestStatus::InProgress, RequestStatus::Reopened => null,
            RequestStatus::ReadyForReview => Resolution::Fixed,
            RequestStatus::WontDo => Resolution::WontDo,
            RequestStatus::Verified => RequestStatus::WontDo === $this->verifiedFrom ? Resolution::WontDo : Resolution::Fixed,
        };
    }

    private function withLifecycle(
        RequestStatus $status,
        ?RequestStatus $statusBeforeClaim = null,
        ?Actor $claimedBy = null,
        ?\DateTimeImmutable $claimedAt = null,
        ?ActorKind $wontDoSetBy = null,
        ?RequestStatus $verifiedFrom = null,
    ): self {
        return self::fromParts(
            $this->requestId,
            $this->reviewId,
            $status,
            $statusBeforeClaim,
            $claimedBy,
            $claimedAt,
            $wontDoSetBy,
            $verifiedFrom,
            $this->links,
            $this->lastUserEventSeq,
            $this->lastAgentEventSeq,
            $this->lastAgentEventAsksQuestion,
            $this->lastEventSeq,
        );
    }

    /**
     * @param list<LinkedCommit> $links
     */
    private function withLinks(array $links): self
    {
        return self::fromParts(
            $this->requestId,
            $this->reviewId,
            $this->status,
            $this->statusBeforeClaim,
            $this->claimedBy,
            $this->claimedAt,
            $this->wontDoSetBy,
            $this->verifiedFrom,
            $links,
            $this->lastUserEventSeq,
            $this->lastAgentEventSeq,
            $this->lastAgentEventAsksQuestion,
            $this->lastEventSeq,
        );
    }

    private function assertStatus(string $transition, RequestStatus ...$allowedStatuses): void
    {
        if (!\in_array($this->status, $allowedStatuses, true)) {
            throw $this->cannot($transition);
        }
    }

    private function assertLinked(CommitSha $commitSha): void
    {
        if (null === $this->linkOf($commitSha)) {
            throw new \LogicException(\sprintf('Commit %s is not linked to request %s.', $commitSha, $this->requestId));
        }
    }

    private function cannot(string $transition): \LogicException
    {
        return new \LogicException(\sprintf('Request %s in status %s does not allow %s.', $this->requestId, $this->status->value, $transition));
    }

    /**
     * @return list<Violation>
     */
    private static function claimViolations(RequestStatus $status, ?RequestStatus $statusBeforeClaim, ?Actor $claimedBy, ?\DateTimeImmutable $claimedAt): array
    {
        $violations = [];
        if (RequestStatus::InProgress !== $status) {
            foreach (['statusBeforeClaim' => $statusBeforeClaim, 'claimedBy' => $claimedBy, 'claimedAt' => $claimedAt] as $field => $value) {
                if (null !== $value) {
                    $violations[] = new Violation($field, 'must be null outside in_progress');
                }
            }

            return $violations;
        }
        if (!\in_array($statusBeforeClaim, [RequestStatus::Open, RequestStatus::Reopened], true)) {
            $violations[] = new Violation('statusBeforeClaim', 'must be open or reopened while in_progress');
        }
        if (ActorKind::Agent !== $claimedBy?->kind) {
            $violations[] = new Violation('claimedBy', 'must be an agent while in_progress');
        }
        if (null === $claimedAt) {
            $violations[] = new Violation('claimedAt', 'is required while in_progress');
        }

        return $violations;
    }

    /**
     * @return list<Violation>
     */
    private static function closingViolations(RequestStatus $status, ?ActorKind $wontDoSetBy, ?RequestStatus $verifiedFrom): array
    {
        $violations = [];
        if (RequestStatus::WontDo === $status && !\in_array($wontDoSetBy, [ActorKind::User, ActorKind::Agent], true)) {
            $violations[] = new Violation('wontDoSetBy', 'must be user or agent while wont_do');
        } elseif (RequestStatus::WontDo !== $status && null !== $wontDoSetBy) {
            $violations[] = new Violation('wontDoSetBy', 'must be null outside wont_do');
        }
        if (RequestStatus::Verified === $status && !\in_array($verifiedFrom, [RequestStatus::ReadyForReview, RequestStatus::WontDo], true)) {
            $violations[] = new Violation('verifiedFrom', 'must be ready_for_review or wont_do while verified');
        } elseif (RequestStatus::Verified !== $status && null !== $verifiedFrom) {
            $violations[] = new Violation('verifiedFrom', 'must be null outside verified');
        }

        return $violations;
    }

    /**
     * @param list<LinkedCommit> $links
     *
     * @return list<Violation>
     */
    private static function linkViolations(array $links): array
    {
        $shas = array_map(static fn (LinkedCommit $link): string => $link->commitSha->toString(), $links);

        return \count(array_unique($shas)) === \count($shas) ? [] : [new Violation('links', 'must not hold the same commit twice')];
    }

    /**
     * @return list<Violation>
     */
    private static function seqViolations(?int $lastUserEventSeq, ?int $lastAgentEventSeq, bool $lastAgentEventAsksQuestion, int $lastEventSeq): array
    {
        $violations = $lastEventSeq < 1 ? [new Violation('lastEventSeq', 'must be 1 or more')] : [];
        foreach (['lastUserEventSeq' => $lastUserEventSeq, 'lastAgentEventSeq' => $lastAgentEventSeq] as $field => $seq) {
            if (null !== $seq && ($seq < 1 || $seq > $lastEventSeq)) {
                $violations[] = new Violation($field, 'must be from 1 to lastEventSeq');
            }
        }
        if ($lastAgentEventAsksQuestion && null === $lastAgentEventSeq) {
            $violations[] = new Violation('lastAgentEventAsksQuestion', 'must be false without lastAgentEventSeq');
        }

        return $violations;
    }
}
