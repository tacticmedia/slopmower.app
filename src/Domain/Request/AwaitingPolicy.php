<?php

declare(strict_types=1);

namespace App\Domain\Request;

/**
 * Rules 1 to 5 of architecture section 6. The first matching rule wins.
 * A thread message is a `request.commented` entry.
 */
final class AwaitingPolicy
{
    public static function evaluate(RequestState $state): Awaiting
    {
        if ($state->closed()) {
            return Awaiting::None;
        }

        if (null !== $state->lastUserCommentSeq && $state->lastUserCommentSeq > ($state->lastAgentEventSeq ?? 0)) {
            return Awaiting::Agent;
        }

        if ($state->lastAgentEventIsQuestion) {
            return Awaiting::User;
        }

        return match ($state->status) {
            RequestStatus::Open, RequestStatus::Reopened, RequestStatus::InProgress => Awaiting::Agent,
            RequestStatus::ReadyForReview, RequestStatus::WontDo => Awaiting::User,
            RequestStatus::Verified => throw new \LogicException('Rule 1 covers verified.'),
        };
    }
}
