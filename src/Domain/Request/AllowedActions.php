<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Review\ReviewSnapshot;

/**
 * The `allowedActions` table of docs/specs/rest-api-requests.md section 1.
 * {@see RequestDecider} refuses exactly the actions that this table does not allow.
 */
final class AllowedActions
{
    /**
     * @return list<Action> in the order of the Action cases, empty on a closed review and for the system
     */
    public static function forCaller(RequestState $state, ReviewSnapshot $review, Actor $caller): array
    {
        if (!$state->reviewId->equals($review->reviewId)) {
            throw new \LogicException(\sprintf('Request %s does not belong to review %s.', $state->requestId, $review->reviewId));
        }
        if ($review->isClosed()) {
            return [];
        }

        return array_values(array_filter(
            Action::cases(),
            static fn (Action $action): bool => self::allows($action, $state, $caller),
        ));
    }

    /**
     * The table row of the action for the caller, without the closed-review rule.
     */
    public static function allows(Action $action, RequestState $state, Actor $caller): bool
    {
        return match ($caller->kind) {
            ActorKind::Agent => self::allowsAgent($action, $state, $caller),
            ActorKind::User => self::allowsUser($action, $state),
            ActorKind::System => false,
        };
    }

    private static function allowsAgent(Action $action, RequestState $state, Actor $agent): bool
    {
        $holdsClaim = $state->isClaimedBy($agent);
        $unclaimed = \in_array($state->status, [RequestStatus::Open, RequestStatus::Reopened], true);

        return match ($action) {
            Action::Reply => true,
            Action::Claim => $unclaimed,
            Action::Release, Action::LinkCommits, Action::MarkReadyForReview => $holdsClaim,
            Action::UnlinkCommit => $holdsClaim && [] !== $state->links,
            Action::MarkWontDo => $unclaimed || $holdsClaim,
            Action::Verify, Action::Reopen => false,
        };
    }

    private static function allowsUser(Action $action, RequestState $state): bool
    {
        return match ($action) {
            Action::Reply => true,
            Action::Claim, Action::LinkCommits, Action::UnlinkCommit, Action::MarkReadyForReview => false,
            Action::Release => RequestStatus::InProgress === $state->status,
            Action::MarkWontDo => \in_array($state->status, [RequestStatus::Open, RequestStatus::Reopened, RequestStatus::InProgress], true),
            Action::Verify => RequestStatus::ReadyForReview === $state->status || ActorKind::Agent === $state->wontDoSetBy,
            Action::Reopen => \in_array($state->status, [RequestStatus::ReadyForReview, RequestStatus::WontDo, RequestStatus::Verified], true),
        };
    }
}
