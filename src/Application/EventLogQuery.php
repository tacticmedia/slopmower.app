<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Event\RecordedEvent;
use App\Domain\Identity\ReviewId;
use App\Entity\ReviewEvent;
use App\Repository\ReviewEventRepository;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

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
        return array_map(
            static fn (ReviewEvent $event): RecordedEvent => $event->toRecordedEvent(),
            $this->events->findAfter($reviewId, $cursor, $limit),
        );
    }
}
