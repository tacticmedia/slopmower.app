<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Event\RecordedEvent;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\Awaiting;
use App\Domain\Request\RequestStatus;
use App\Entity\ChangeRequest;
use App\Entity\ReviewEvent;
use App\EventStore\ReadSnapshotSql;
use App\Repository\ReviewEventRepository;
use App\Repository\ReviewRepository;
use App\Tenancy\TenantContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * V10, docs/specs/rest-api-requests.md section 4, in one snapshot.
 */
final class UpdatesQuery
{
    private const int MAX_NEW_EVENTS = 10;
    private const int MAX_REVIEW_EVENTS = 50;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReadSnapshotSql $snapshot,
        private readonly TenantContext $tenantContext,
        private readonly ReviewRepository $reviews,
        private readonly ReviewEventRepository $events,
        private readonly RequestQuery $requests,
        private readonly ThreadQuery $threads,
    ) {
    }

    /**
     * @param int|null $cursor Null for the requests that wait for the caller, else the cursor of the previous page
     * @param int      $limit  Requests without a cursor, events with one
     *
     * @throws InvalidInput A cursor ahead of the server
     * @throws NotFound
     */
    public function get(ReviewId $reviewId, Actor $caller, ?int $cursor, int $limit, bool $includeOwn): UpdatesPage
    {
        return $this->snapshot->read($this->tenantContext->get(), function (int $eventSeq) use ($reviewId, $caller, $cursor, $limit, $includeOwn): UpdatesPage {
            if (null !== $cursor && $cursor > $eventSeq) {
                throw InvalidInput::field('cursor', "Is ahead of the server, whose last event is {$eventSeq}.");
            }

            $review = $this->reviews->findFresh($reviewId) ?? throw new NotFound();
            [$requests, $reviewEvents, $nextCursor, $hasMore] = null === $cursor
                ? $this->awaitingRequests($reviewId, $caller, $eventSeq, $limit)
                : $this->eventsAfter($reviewId, $caller, $cursor, $eventSeq, $limit, $includeOwn);
            $awaitingTotal = (int) $this->awaiting($reviewId, $caller)->select('COUNT(request.id)')->getQuery()->getSingleScalarResult();

            return new UpdatesPage($reviewId, $review->getState(), $nextCursor, $hasMore, $awaitingTotal, $requests, $reviewEvents);
        });
    }

    /**
     * @return array{list<UpdatedRequest>, list<EventView>, int, bool}
     */
    private function awaitingRequests(ReviewId $reviewId, Actor $caller, int $eventSeq, int $limit): array
    {
        /** @var list<ChangeRequest> $requests */
        $requests = $this->awaiting($reviewId, $caller)
            ->select('request')
            ->orderBy('request.lastEventSeq')
            ->addOrderBy('request.id')
            ->setMaxResults($limit)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
        $updated = [];

        foreach ($this->requests->summaries($requests) as $summary) {
            $thread = $this->threads->entries($reviewId, $summary->state->id, $eventSeq);
            $unseen = $thread;

            foreach ($thread as $index => $entry) {
                if ($entry->event->actor->equals($caller)) {
                    $unseen = array_slice($thread, $index + 1);
                }
            }

            $newEvents = array_slice($unseen, -self::MAX_NEW_EVENTS);
            $updated[] = new UpdatedRequest($summary, array_map(static fn (ThreadEntry $entry): EventView => $entry->eventView(), $newEvents), count($unseen) - count($newEvents));
        }

        $reviewEvents = array_map(
            static fn (ReviewEvent $event): EventView => new EventView($event->toRecordedEvent(), null),
            $this->events->findReviewEvents($reviewId, $eventSeq, self::MAX_REVIEW_EVENTS),
        );

        return [$updated, $reviewEvents, $eventSeq, false];
    }

    /**
     * @return array{list<UpdatedRequest>, list<EventView>, int, bool}
     */
    private function eventsAfter(ReviewId $reviewId, Actor $caller, int $cursor, int $eventSeq, int $limit, bool $includeOwn): array
    {
        $rows = $this->events->findInRange($reviewId, $cursor, $eventSeq, $includeOwn ? null : $caller, $limit + 1);
        $hasMore = count($rows) > $limit;
        $events = array_map(static fn (ReviewEvent $event): RecordedEvent => $event->toRecordedEvent(), array_slice($rows, 0, $limit));
        $groups = [];
        $reviewEvents = [];

        foreach ($events as $event) {
            if (in_array($event->type, ReviewEventRepository::REVIEW_EVENT_TYPES, true)) {
                $reviewEvents[] = new EventView($event, null);
                continue;
            }

            foreach ($event->requestIds() as $requestId) {
                $groups[$requestId->toString()][] = $event;
            }
        }

        $updated = [];

        foreach ($this->requests->summaries($this->requestsInOrder(array_map(strval(...), array_keys($groups)))) as $summary) {
            $thread = $this->threads->entriesBySeq($reviewId, $summary->state->id, $eventSeq);
            $updated[] = new UpdatedRequest($summary, array_map(
                static fn (RecordedEvent $event): EventView => ($thread[$event->seq] ?? throw new \LogicException("The event {$event->seq} is not in the thread of {$summary->state->id}."))->eventView(),
                $groups[$summary->state->id->toString()],
            ), 0);
        }

        $last = array_last($events);

        return [$updated, $reviewEvents, $hasMore && null !== $last ? $last->seq : $eventSeq, $hasMore];
    }

    /**
     * The requests whose `awaiting` matches the caller. An agent does not wait for a request that another agent holds.
     */
    private function awaiting(ReviewId $reviewId, Actor $caller): QueryBuilder
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->from(ChangeRequest::class, 'request')
            ->where('request.review = :review')
            ->andWhere('request.awaiting = :awaiting')
            ->setParameter('review', Ulid::fromString($reviewId->toString()), UlidType::NAME)
            ->setParameter('awaiting', match ($caller->kind) {
                ActorKind::User => Awaiting::User->value,
                ActorKind::Agent => Awaiting::Agent->value,
                ActorKind::System => throw new \LogicException('The system reads no updates.'),
            });

        if (ActorKind::Agent === $caller->kind && null !== $caller->userId) {
            $builder->andWhere('request.status <> :inProgress OR (request.claimedBy.userId = :callerUserId AND request.claimedBy.agentName = :callerAgentName)')
                ->setParameter('inProgress', RequestStatus::InProgress->value)
                ->setParameter('callerUserId', Ulid::fromString($caller->userId->toString()), UlidType::NAME)
                ->setParameter('callerAgentName', $caller->agentName);
        }

        return $builder;
    }

    /**
     * @param list<string> $ids Request IDs
     *
     * @return list<ChangeRequest> In the order of $ids
     */
    private function requestsInOrder(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<ChangeRequest> $requests */
        $requests = $this->entityManager->createQueryBuilder()
            ->select('request')
            ->from(ChangeRequest::class, 'request')
            ->where('request.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (string $id): string => Ulid::fromString($id)->toRfc4122(), $ids), ArrayParameterType::STRING)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
        $byId = [];

        foreach ($requests as $request) {
            $byId[$request->getId()->toString()] = $request;
        }

        return array_map(static fn (string $id): ChangeRequest => $byId[$id] ?? throw new \LogicException("The request {$id} of an event does not exist."), $ids);
    }
}
