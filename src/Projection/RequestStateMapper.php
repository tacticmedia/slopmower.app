<?php

declare(strict_types=1);

namespace App\Projection;

use App\Domain\Request\AwaitingPolicy;
use App\Domain\Request\LinkedCommit;
use App\Domain\Request\RequestState;
use App\Entity\ChangeRequest;
use App\Entity\CommitLink;
use App\Entity\Review;

/**
 * Commit links are written from the commit.* events, not from the state, because the state lacks their git data.
 */
final class RequestStateMapper
{
    /**
     * Times have second precision, so links of one second are in SHA order.
     *
     * @param list<CommitLink> $links The links of the request in any order
     */
    public static function toState(ChangeRequest $request, array $links): RequestState
    {
        foreach ($links as $link) {
            if (!$link->getRequest()->getId()->equals($request->getId())) {
                throw new \LogicException("The commit link {$link->getCommitSha()} belongs to another request.");
            }
        }

        usort($links, static fn (CommitLink $a, CommitLink $b): int => [$a->getLinkedAt(), $a->getCommitSha()->toString()] <=> [$b->getLinkedAt(), $b->getCommitSha()->toString()]);

        return new RequestState(
            id: $request->getId(),
            reviewId: $request->getReview()->getId(),
            anchor: $request->getAnchor(),
            body: $request->getBody(),
            author: $request->getAuthor(),
            createdAt: $request->getCreatedAt(),
            status: $request->getStatus(),
            statusBeforeClaim: $request->getStatusBeforeClaim(),
            claimedBy: $request->getClaimedBy(),
            claimedAt: $request->getClaimedAt(),
            wontDoBy: $request->getWontDoBy(),
            resolution: $request->getResolution(),
            links: array_map(static fn (CommitLink $link): LinkedCommit => new LinkedCommit($link->getCommitSha(), $link->getState()), $links),
            pullRequestUrls: $request->getPullRequestUrls(),
            lastUserCommentSeq: $request->getLastUserCommentSeq(),
            lastAgentEventSeq: $request->getLastAgentEventSeq(),
            lastAgentEventIsQuestion: $request->isLastAgentEventQuestion(),
            lastEventSeq: $request->getLastEventSeq(),
            updatedAt: $request->getUpdatedAt(),
            carriedFrom: $request->getCarriedFrom(),
            requireApproval: $request->requiresApproval(),
            proposal: $request->getProposal(),
            suggestion: $request->getSuggestionState(),
            title: $request->getTitle(),
        );
    }

    public static function toEntity(Review $review, RequestState $state): ChangeRequest
    {
        if (!$review->getId()->equals($state->reviewId)) {
            throw new \LogicException("The request {$state->id} belongs to another review.");
        }

        $request = new ChangeRequest($state->id, $review, $state->anchor, $state->body, $state->author, $state->createdAt, $state->carriedFrom);
        self::update($request, $state);

        return $request;
    }

    /**
     * Also stores `awaiting` from {@see AwaitingPolicy}.
     */
    public static function update(ChangeRequest $request, RequestState $state): void
    {
        if (!$request->getId()->equals($state->id)) {
            throw new \LogicException("The state of {$state->id} does not fit the request {$request->getId()}.");
        }

        $request->project(
            status: $state->status,
            statusBeforeClaim: $state->statusBeforeClaim,
            claimedBy: $state->claimedBy,
            claimedAt: $state->claimedAt,
            wontDoBy: $state->wontDoBy,
            resolution: $state->resolution,
            awaiting: AwaitingPolicy::evaluate($state),
            lastUserCommentSeq: $state->lastUserCommentSeq,
            lastAgentEventSeq: $state->lastAgentEventSeq,
            lastAgentEventIsQuestion: $state->lastAgentEventIsQuestion,
            lastEventSeq: $state->lastEventSeq,
            updatedAt: $state->updatedAt,
            requireApproval: $state->requireApproval,
            proposal: $state->proposal,
            suggestionState: $state->suggestion,
            pullRequestUrls: $state->pullRequestUrls,
            title: $state->title,
        );
    }
}
