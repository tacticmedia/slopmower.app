<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\IndexReview;
use App\Message\QueueStalledIndexesJob;
use App\Messenger\TenantStamp;
use App\Repository\ReviewRepository;
use App\Tenancy\TenantFilter;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D95: the hourly job of {@see \App\Schedule}, listed in `app.tenantless_messages`. Queues the index job again for a
 * review whose job was lost between the commit of review.started and the dispatch.
 */
#[AsMessageHandler]
final class StalledIndexHandler
{
    private const string STALLED_AFTER = '-1 hour';

    public function __construct(
        private readonly ReviewRepository $reviews,
        private readonly TenantFilter $tenantFilter,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(QueueStalledIndexesJob $job): void
    {
        $stalled = $this->tenantFilter->suspend(fn (): array => $this->reviews->findOpenWithIndexPendingSince($this->clock->now()->modify(self::STALLED_AFTER)));

        foreach ($stalled as $review) {
            $this->bus->dispatch(new IndexReview($review->getId()), [new TenantStamp($review->getTenantId())]);
        }
    }
}
