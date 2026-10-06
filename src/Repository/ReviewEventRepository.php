<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Actor\Actor;
use App\Domain\Event\EventType;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\UserId;
use App\Entity\ReviewEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReviewEvent>
 */
final class ReviewEventRepository extends ServiceEntityRepository
{
    /** The events of updates-page.schema.json `reviewEvents`. */
    public const array REVIEW_EVENT_TYPES = [EventType::ReviewStarted, EventType::BatchSubmitted, EventType::ReviewClosed];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReviewEvent::class);
    }

    /**
     * The thread entries of a request: every event that names it, except batch.submitted.
     *
     * @return list<ReviewEvent> In seq order
     */
    public function findThread(ReviewId $reviewId, RequestId $requestId, ?int $upToSeq = null): array
    {
        $builder = $this->ofReview($reviewId)
            ->andWhere('JSONB_CONTAINS(event.requestIds, :requestIds) = TRUE')
            ->andWhere('event.type <> :batchSubmitted')
            ->orderBy('event.seq')
            ->setParameter('requestIds', json_encode([$requestId->toString()], \JSON_THROW_ON_ERROR))
            ->setParameter('batchSubmitted', EventType::BatchSubmitted->value);

        if (null !== $upToSeq) {
            $builder->andWhere('event.seq <= :upToSeq')->setParameter('upToSeq', $upToSeq);
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * @param Actor|null $excludedActor Its events are left out
     *
     * @return list<ReviewEvent> In seq order
     */
    public function findInRange(ReviewId $reviewId, int $afterSeq, int $upToSeq, ?Actor $excludedActor, int $limit): array
    {
        $builder = $this->ofReview($reviewId)
            ->andWhere('event.seq > :afterSeq')
            ->andWhere('event.seq <= :upToSeq')
            ->orderBy('event.seq')
            ->setMaxResults($limit)
            ->setParameter('afterSeq', $afterSeq)
            ->setParameter('upToSeq', $upToSeq);

        if (null !== $excludedActor) {
            $sameActor = ['event.actor.kind = :excludedKind'];
            $builder->setParameter('excludedKind', $excludedActor->kind->value);

            if (null !== $excludedActor->userId) {
                $sameActor[] = 'event.actor.userId = :excludedUserId';
                $builder->setParameter('excludedUserId', $excludedActor->userId, UserId::class);
            }

            if (null !== $excludedActor->agentName) {
                $sameActor[] = 'event.actor.agentName = :excludedAgentName';
                $builder->setParameter('excludedAgentName', $excludedActor->agentName);
            }

            $builder->andWhere('NOT ('.implode(' AND ', $sameActor).')');
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * @return list<ReviewEvent> The newest $limit events of {@see REVIEW_EVENT_TYPES}, in seq order
     */
    public function findReviewEvents(ReviewId $reviewId, int $upToSeq, int $limit): array
    {
        /** @var list<ReviewEvent> $newestFirst */
        $newestFirst = $this->ofReview($reviewId)
            ->andWhere('event.type IN (:types)')
            ->andWhere('event.seq <= :upToSeq')
            ->orderBy('event.seq', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('types', array_map(static fn (EventType $type): string => $type->value, self::REVIEW_EVENT_TYPES), ArrayParameterType::STRING)
            ->setParameter('upToSeq', $upToSeq)
            ->getQuery()
            ->getResult();

        return array_reverse($newestFirst);
    }

    /**
     * @return array<string, EventId> The ID of the request.opened event, by request ID
     */
    public function findOpenedEventIds(ReviewId $reviewId): array
    {
        /** @var list<array{eventId: EventId, requestId: RequestId}> $rows */
        $rows = $this->ofReview($reviewId)
            ->select('event.id AS eventId', 'event.requestId AS requestId')
            ->andWhere('event.type = :opened')
            ->setParameter('opened', EventType::RequestOpened->value)
            ->getQuery()
            ->getArrayResult();
        $eventIds = [];

        foreach ($rows as $row) {
            $eventIds[$row['requestId']->toString()] = $row['eventId'];
        }

        return $eventIds;
    }

    private function ofReview(ReviewId $reviewId): QueryBuilder
    {
        return $this->createQueryBuilder('event')
            ->where('event.reviewId = :reviewId')
            ->setParameter('reviewId', $reviewId, ReviewId::class);
    }
}
