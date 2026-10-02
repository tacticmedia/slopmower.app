<?php

declare(strict_types=1);

namespace App\Live;

use App\Domain\Identity\RequestId;
use App\EventStore\EventsCommitted;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The `events` update: one per committed transaction, identified by the seq of its last event.
 */
#[AsEventListener]
final class PublishCommittedEvents
{
    public function __construct(
        private readonly LivePublisher $publisher,
        private readonly LiveTopics $topics,
    ) {
    }

    public function __invoke(EventsCommitted $committed): void
    {
        $this->publisher->publish(
            $this->topics->review($committed->tenantId, $committed->reviewId),
            [
                'type' => 'events',
                'reviewId' => $committed->reviewId->toString(),
                'seq' => $committed->lastSeq,
                'requestIds' => array_map(static fn (RequestId $requestId): string => $requestId->toString(), $committed->requestIds),
            ],
            "urn:holistic-review:{$committed->tenantId}:{$committed->lastSeq}",
        );
    }
}
