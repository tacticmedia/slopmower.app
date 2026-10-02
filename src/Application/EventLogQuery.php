<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Event\RecordedEvent;
use App\Domain\Identity\ReviewId;
use App\Entity\ReviewEvent;
use App\Repository\ReviewEventRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Uid\Ulid;

#[Autoconfigure(public: true)]
final class EventLogQuery
{
    public function __construct(private readonly ReviewEventRepository $events)
    {
    }

    /**
     * @return list<RecordedEvent> In seq order
     */
    public function after(ReviewId $reviewId, int $cursor, int $limit = 100): array
    {
        /** @var list<ReviewEvent> $events */
        $events = $this->events->createQueryBuilder('event')
            ->where('event.reviewId = :reviewId')
            ->andWhere('event.seq > :cursor')
            ->orderBy('event.seq')
            ->setMaxResults($limit)
            ->setParameter('reviewId', Ulid::fromString($reviewId->toString()), UlidType::NAME)
            ->setParameter('cursor', $cursor)
            ->getQuery()
            ->getResult();

        return array_map(static fn (ReviewEvent $event): RecordedEvent => $event->toRecordedEvent(), $events);
    }
}
