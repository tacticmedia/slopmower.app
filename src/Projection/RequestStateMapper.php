<?php

declare(strict_types=1);

namespace App\Projection;

use App\Domain\Error\InvalidInput;
use App\Domain\Request\AwaitingPolicy;
use App\Domain\Request\LinkedCommit;
use App\Domain\Request\RequestState;
use App\Entity\ChangeRequest;
use App\Entity\CommitLink;

/**
 * Maps between the {@see ChangeRequest} projection with its {@see CommitLink} rows and the domain {@see RequestState}.
 * The commit links come from the `commit.*` events, so {@see self::apply()} leaves them to the caller.
 */
final class RequestStateMapper
{
    /**
     * @throws \LogicException for the state of another request
     */
    public static function apply(RequestState $state, ChangeRequest $request, \DateTimeImmutable $updatedAt): void
    {
        if (!$state->requestId->equals($request->getId()) || !$state->reviewId->equals($request->getReviewId())) {
            throw new \LogicException(\sprintf('The state of request %s does not belong to request %s.', $state->requestId, $request->getId()));
        }

        $request->changeLifecycle(
            status: $state->status,
            statusBeforeClaim: $state->statusBeforeClaim,
            claimedBy: $state->claimedBy,
            claimedAt: $state->claimedAt,
            wontDoSetBy: $state->wontDoSetBy,
            verifiedFrom: $state->verifiedFrom,
            lastUserEventSeq: $state->lastUserEventSeq,
            lastAgentEventSeq: $state->lastAgentEventSeq,
            lastAgentEventAsksQuestion: $state->lastAgentEventAsksQuestion,
            awaiting: AwaitingPolicy::of($state),
            resolution: $state->resolution(),
            lastEventSeq: $state->lastEventSeq,
            updatedAt: $updatedAt,
        );
    }

    /**
     * @param list<CommitLink> $commitLinks every link of the request, in any order
     *
     * @throws \LogicException for a link of another request, or stored `awaiting` or `resolution` that the state does not give
     * @throws InvalidInput    for stored columns that break the rules of {@see RequestState::fromParts()}
     */
    public static function toState(ChangeRequest $request, array $commitLinks): RequestState
    {
        foreach ($commitLinks as $commitLink) {
            if (!$commitLink->getRequestId()->equals($request->getId())) {
                throw new \LogicException(\sprintf('Commit link %s belongs to request %s, not %s.', $commitLink->getCommitSha(), $commitLink->getRequestId(), $request->getId()));
            }
        }
        usort($commitLinks, static fn (CommitLink $first, CommitLink $second): int => $first->getLinkedSeq() <=> $second->getLinkedSeq());

        $state = RequestState::fromParts(
            $request->getId(),
            $request->getReviewId(),
            $request->getStatus(),
            $request->getStatusBeforeClaim(),
            $request->getClaimedBy(),
            $request->getClaimedAt(),
            $request->getWontDoSetBy(),
            $request->getVerifiedFrom(),
            array_map(static fn (CommitLink $commitLink): LinkedCommit => $commitLink->toLinkedCommit(), $commitLinks),
            $request->getLastUserEventSeq(),
            $request->getLastAgentEventSeq(),
            $request->lastAgentEventAsksQuestion(),
            $request->getLastEventSeq(),
        );
        if (AwaitingPolicy::of($state) !== $request->getAwaiting() || $state->resolution() !== $request->getResolution()) {
            throw new \LogicException(\sprintf('The stored awaiting or resolution of request %s does not match its state.', $request->getId()));
        }

        return $state;
    }
}
