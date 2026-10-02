<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Actor\ActorKind;
use App\Domain\Event\CommitFoundEvent;
use App\Domain\Event\CommitLinkedEvent;
use App\Domain\Event\CommitUnlinkedEvent;
use App\Domain\Event\RecordedEvent;
use App\Domain\Event\RequestClaimedEvent;
use App\Domain\Event\RequestCommentedEvent;
use App\Domain\Event\RequestOpenedEvent;
use App\Domain\Event\RequestReadyForReviewEvent;
use App\Domain\Event\RequestReleasedEvent;
use App\Domain\Event\RequestReopenedEvent;
use App\Domain\Event\RequestVerifiedEvent;
use App\Domain\Event\RequestWontDoEvent;
use App\Domain\Identity\RequestId;

/**
 * Applies the thread entries of one request: its request events and the commit events that name it.
 * Review events and batch.submitted leave the state unchanged.
 */
final class RequestReducer
{
    private const array UNCLAIMED = ['statusBeforeClaim' => null, 'claimedBy' => null, 'claimedAt' => null];

    /**
     * @param RequestState|null $state Null only for request.opened
     */
    public static function apply(?RequestState $state, RecordedEvent $recorded): RequestState
    {
        $event = $recorded->event;

        if ($event instanceof RequestOpenedEvent) {
            return null === $state ? self::opened($recorded, $event) : throw new \LogicException('The request is open already.');
        }

        if (null === $state) {
            throw new \LogicException("{$recorded->type->value} needs an opened request.");
        }

        $changes = match (true) {
            $event instanceof RequestCommentedEvent => [],
            $event instanceof RequestClaimedEvent => [
                'status' => RequestStatus::InProgress,
                'statusBeforeClaim' => $state->status,
                'claimedBy' => $recorded->actor,
                'claimedAt' => $recorded->occurredAt,
            ],
            $event instanceof RequestReleasedEvent => [
                ...self::UNCLAIMED,
                'status' => $state->statusBeforeClaim ?? throw new \LogicException('Only a claimed request is released.'),
            ],
            $event instanceof CommitLinkedEvent => [
                'links' => [...$state->links, new LinkedCommit($event->commitSha, $event->available ? CommitLinkState::Available : CommitLinkState::Pending)],
            ],
            $event instanceof CommitUnlinkedEvent => [
                'links' => array_values(array_filter($state->links, static fn (LinkedCommit $link): bool => !$link->commitSha->equals($event->commitSha))),
            ],
            $event instanceof CommitFoundEvent => [
                'links' => array_map(
                    static fn (LinkedCommit $link): LinkedCommit => $link->commitSha->equals($event->commitSha) ? new LinkedCommit($link->commitSha, CommitLinkState::Available) : $link,
                    $state->links,
                ),
            ],
            $event instanceof RequestReadyForReviewEvent => [
                ...self::UNCLAIMED,
                'status' => RequestStatus::ReadyForReview,
                'resolution' => Resolution::Fixed,
            ],
            $event instanceof RequestWontDoEvent => [
                ...self::UNCLAIMED,
                'status' => RequestStatus::WontDo,
                'wontDoBy' => $recorded->actor->kind,
                'resolution' => Resolution::WontDo,
            ],
            $event instanceof RequestVerifiedEvent => ['status' => RequestStatus::Verified, 'wontDoBy' => null],
            $event instanceof RequestReopenedEvent => ['status' => RequestStatus::Reopened, 'wontDoBy' => null, 'resolution' => null],
            default => null,
        };

        if (null === $changes) {
            return $state;
        }

        self::assertNames($recorded, $state->id);

        if (($event instanceof CommitUnlinkedEvent || $event instanceof CommitFoundEvent) && null === $state->link($event->commitSha)) {
            throw new \LogicException("{$recorded->type->value} names a commit that is not linked to the request.");
        }

        return $state->with([...$changes, ...self::tracking($recorded)]);
    }

    private static function opened(RecordedEvent $recorded, RequestOpenedEvent $event): RequestState
    {
        return new RequestState(
            id: $recorded->requestId ?? throw new \LogicException('request.opened needs a requestId.'),
            reviewId: $recorded->reviewId,
            anchor: $event->anchor,
            body: $event->body,
            author: $recorded->actor,
            createdAt: $recorded->occurredAt,
            status: RequestStatus::Open,
            statusBeforeClaim: null,
            claimedBy: null,
            claimedAt: null,
            wontDoBy: null,
            resolution: null,
            links: [],
            lastUserCommentSeq: null,
            lastAgentEventSeq: null,
            lastAgentEventIsQuestion: false,
            lastEventSeq: $recorded->seq,
            updatedAt: $recorded->occurredAt,
        )->with(self::tracking($recorded));
    }

    /**
     * @return array{lastEventSeq: int, updatedAt: \DateTimeImmutable, lastAgentEventSeq?: int, lastAgentEventIsQuestion?: bool, lastUserCommentSeq?: int}
     */
    private static function tracking(RecordedEvent $recorded): array
    {
        $tracking = ['lastEventSeq' => $recorded->seq, 'updatedAt' => $recorded->occurredAt];
        $event = $recorded->event;
        $isComment = $event instanceof RequestCommentedEvent;

        if (ActorKind::Agent === $recorded->actor->kind) {
            $tracking['lastAgentEventSeq'] = $recorded->seq;
            $tracking['lastAgentEventIsQuestion'] = $isComment && $event->question;
        } elseif (ActorKind::User === $recorded->actor->kind && $isComment) {
            $tracking['lastUserCommentSeq'] = $recorded->seq;
        }

        return $tracking;
    }

    private static function assertNames(RecordedEvent $recorded, RequestId $id): void
    {
        $event = $recorded->event;
        $named = match (true) {
            $event instanceof CommitLinkedEvent, $event instanceof CommitFoundEvent => $event->requestIds,
            $event instanceof CommitUnlinkedEvent => [$event->requestId],
            default => [$recorded->requestId],
        };

        foreach ($named as $namedId) {
            if (null !== $namedId && $namedId->equals($id)) {
                return;
            }
        }

        throw new \LogicException("{$recorded->type->value} does not name the request {$id}.");
    }
}
