<?php

declare(strict_types=1);

namespace App;

use App\Message\PruneRateLimiterCacheJob;
use App\Message\PurgeFailedMessagesJob;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        // A fixed start time, so a restart of the worker or a new deployment does not move the daily run.
        $dailyFrom = new \DateTimeImmutable('03:00', new \DateTimeZone('UTC'));

        return new SymfonySchedule()
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(
                RecurringMessage::every('1 day', new PruneRateLimiterCacheJob(), $dailyFrom),
                RecurringMessage::every('1 day', new PurgeFailedMessagesJob(), $dailyFrom),
            );
    }
}
