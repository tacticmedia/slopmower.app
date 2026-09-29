<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Request\Action;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;

/**
 * The `allowedActions` table of docs/specs/rest-api-requests.md section 1 on an open review, written from the docs
 * and not from {@see \App\Domain\Request\AllowedActions}.
 */
final class SpecAllowedActions
{
    /**
     * @return list<Action> in the row order of the table
     */
    public static function for(RequestState $state, Actor $caller): array
    {
        $status = $state->status;
        $inProgress = RequestStatus::InProgress === $status;
        $openOrReopened = RequestStatus::Open === $status || RequestStatus::Reopened === $status;
        $claimedByCaller = $inProgress && null !== $state->claimedBy && self::sameActor($state->claimedBy, $caller);

        $rows = match ($caller->kind) {
            ActorKind::Agent => [
                'reply' => true,
                'claim' => $openOrReopened,
                'release' => $claimedByCaller,
                'link_commits' => $claimedByCaller,
                'unlink_commit' => $claimedByCaller && [] !== $state->links,
                'mark_ready_for_review' => $claimedByCaller,
                'mark_wont_do' => $openOrReopened || $claimedByCaller,
                'verify' => false,
                'reopen' => false,
            ],
            ActorKind::User => [
                'reply' => true,
                'claim' => false,
                'release' => $inProgress,
                'link_commits' => false,
                'unlink_commit' => false,
                'mark_ready_for_review' => false,
                'mark_wont_do' => $openOrReopened || $inProgress,
                'verify' => RequestStatus::ReadyForReview === $status
                    || (RequestStatus::WontDo === $status && ActorKind::Agent === $state->wontDoSetBy),
                'reopen' => \in_array($status, [RequestStatus::ReadyForReview, RequestStatus::WontDo, RequestStatus::Verified], true),
            ],
            ActorKind::System => throw new \LogicException('The table has no system caller.'),
        };

        return array_map(Action::from(...), array_keys(array_filter($rows)));
    }

    /**
     * Two actors are the same when kind, userId, and agentName match.
     */
    public static function sameActor(Actor $first, Actor $second): bool
    {
        return $first->kind === $second->kind
            && $first->userId?->toString() === $second->userId?->toString()
            && $first->agentName === $second->agentName;
    }
}
