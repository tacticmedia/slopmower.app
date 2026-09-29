<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Event\CommitFound;
use App\Domain\Event\CommitLinked;
use App\Domain\Event\CommitUnlinked;
use App\Domain\Event\RecordedEvent;
use App\Domain\Event\RequestClaimed;
use App\Domain\Event\RequestCommented;
use App\Domain\Event\RequestOpened;
use App\Domain\Event\RequestReadyForReview;
use App\Domain\Event\RequestReleased;
use App\Domain\Event\RequestReopened;
use App\Domain\Event\RequestVerified;
use App\Domain\Event\RequestWontDo;
use App\Domain\Identity\RequestId;

final class RequestReducer
{
    /**
     * @param RequestState|null $state null before request.opened
     *
     * @throws \LogicException for an event that does not concern the request, comes out of seq order, or does not fit its status
     */
    public static function apply(?RequestState $state, RecordedEvent $recorded): RequestState
    {
        $event = $recorded->event;
        if (null === $state) {
            $state = $event instanceof RequestOpened && null !== $recorded->requestId
                ? RequestState::opened($recorded->requestId, $recorded->reviewId, $recorded->seq)
                : throw new \LogicException(\sprintf('The first event of a request must be request.opened, not %s.', $recorded->type->value));
        } else {
            self::assertConcerns($state, $recorded);
            $state = match (true) {
                $event instanceof RequestCommented => $state,
                $event instanceof RequestClaimed => $state->claim($recorded->actor, $recorded->occurredAt),
                $event instanceof RequestReleased => $state->release(),
                $event instanceof CommitLinked => $state->linkCommit($event->commitSha, $event->available ? CommitLinkState::Available : CommitLinkState::Pending),
                $event instanceof CommitUnlinked => $state->unlinkCommit($event->commitSha),
                $event instanceof CommitFound => $state->markCommitAvailable($event->commitSha),
                $event instanceof RequestReadyForReview => $state->markReadyForReview(),
                $event instanceof RequestWontDo => $state->markWontDo($recorded->actor->kind),
                $event instanceof RequestVerified => $state->verify(),
                $event instanceof RequestReopened => $state->reopen(),
                default => throw new \LogicException(\sprintf('Request %s is already open.', $state->requestId)),
            };
        }

        return $state->recordEvent($recorded->actor->kind, $recorded->seq, $event instanceof RequestCommented && $event->question);
    }

    /**
     * Folds the event log of a review into the state of each of its requests.
     *
     * @param iterable<RecordedEvent> $events in seq order
     *
     * @return array<string, RequestState> keyed by request ID, in the order the requests opened
     */
    public static function replay(iterable $events): array
    {
        $states = [];
        foreach ($events as $recorded) {
            foreach (self::affectedRequestIds($recorded) as $requestId) {
                $states[$requestId->toString()] = self::apply($states[$requestId->toString()] ?? null, $recorded);
            }
        }

        return $states;
    }

    /**
     * The requests whose state the event changes. Review and batch events change none.
     *
     * @return list<RequestId>
     */
    public static function affectedRequestIds(RecordedEvent $recorded): array
    {
        $event = $recorded->event;

        return match (true) {
            $event instanceof CommitLinked, $event instanceof CommitFound => $event->requestIds,
            $event instanceof CommitUnlinked => [$event->requestId],
            null !== $recorded->requestId => [$recorded->requestId],
            default => [],
        };
    }

    private static function assertConcerns(RequestState $state, RecordedEvent $recorded): void
    {
        $concernsRequest = array_any(
            self::affectedRequestIds($recorded),
            static fn (RequestId $requestId): bool => $requestId->equals($state->requestId),
        );
        if (!$concernsRequest || !$recorded->reviewId->equals($state->reviewId)) {
            throw new \LogicException(\sprintf('Event %s (%s) does not concern request %s.', $recorded->id, $recorded->type->value, $state->requestId));
        }
        if ($recorded->seq <= $state->lastEventSeq) {
            throw new \LogicException(\sprintf('Event %s has seq %d, which does not follow seq %d of request %s.', $recorded->id, $recorded->seq, $state->lastEventSeq, $state->requestId));
        }
    }
}
