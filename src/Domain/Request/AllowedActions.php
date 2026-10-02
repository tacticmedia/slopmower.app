<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Review\ReviewSnapshot;

/**
 * The `allowedActions` table of docs/specs/rest-api-requests.md section 1. {@see RequestDecider} refuses every other action with
 * invalid_transition, except a repeated claim by the holder, which succeeds without an event.
 */
final class AllowedActions
{
    /**
     * @return list<Action>
     */
    public static function for(RequestState $state, ReviewSnapshot $review, Actor $caller): array
    {
        if ($review->isClosed()) {
            return [];
        }

        return array_values(array_filter(Action::cases(), static fn (Action $action): bool => self::permits($action, $state, $caller)));
    }

    private static function permits(Action $action, RequestState $state, Actor $caller): bool
    {
        $isAgent = ActorKind::Agent === $caller->kind;
        $isUser = ActorKind::User === $caller->kind;
        $holds = $isAgent && $state->isClaimedBy($caller);
        $status = $state->status;
        $inProgress = RequestStatus::InProgress === $status;
        $openOrReopened = RequestStatus::Open === $status || RequestStatus::Reopened === $status;

        return match ($action) {
            Action::Reply => $isAgent || $isUser,
            Action::Claim => $isAgent && $openOrReopened,
            Action::Release => $holds || ($isUser && $inProgress),
            Action::LinkCommits, Action::MarkReadyForReview => $holds,
            Action::UnlinkCommit => $holds && [] !== $state->links,
            Action::MarkWontDo => ($isAgent && ($openOrReopened || $holds)) || ($isUser && ($openOrReopened || $inProgress)),
            Action::Verify => $isUser && (RequestStatus::ReadyForReview === $status || (RequestStatus::WontDo === $status && ActorKind::Agent === $state->wontDoBy)),
            Action::Reopen => $isUser && in_array($status, [RequestStatus::ReadyForReview, RequestStatus::WontDo, RequestStatus::Verified], true),
        };
    }
}
