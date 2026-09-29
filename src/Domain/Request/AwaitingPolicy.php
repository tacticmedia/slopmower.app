<?php

declare(strict_types=1);

namespace App\Domain\Request;

/**
 * Rules 1 to 5 of architecture section 6. The first rule that matches wins.
 */
final class AwaitingPolicy
{
    public static function of(RequestState $state): Awaiting
    {
        return match (true) {
            $state->isClosed() => Awaiting::None,
            self::userActedLast($state) => Awaiting::Agent,
            $state->lastAgentEventAsksQuestion => Awaiting::User,
            \in_array($state->status, [RequestStatus::Open, RequestStatus::Reopened, RequestStatus::InProgress], true) => Awaiting::Agent,
            default => Awaiting::User,
        };
    }

    /**
     * Every request event is a thread entry, so a reopen or an Unassign note counts as a user message.
     */
    private static function userActedLast(RequestState $state): bool
    {
        return null !== $state->lastUserEventSeq
            && (null === $state->lastAgentEventSeq || $state->lastUserEventSeq > $state->lastAgentEventSeq);
    }
}
