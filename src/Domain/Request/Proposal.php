<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Identity\EventId;

/**
 * The current proposal of a claim. {@see RequestReducer} sets it to null when the claim ends without ready_for_review.
 */
final readonly class Proposal
{
    /**
     * @param EventId $eventId The request.proposed event
     */
    public function __construct(
        public EventId $eventId,
        public ProposalState $state,
    ) {
    }

    public function isPending(): bool
    {
        return ProposalState::Pending === $this->state;
    }
}
