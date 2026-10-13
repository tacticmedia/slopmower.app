<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PruneRateLimiterCacheJob;
use App\Message\PurgeFailedMessagesJob;
use App\Messenger\FailedMessageSql;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The daily jobs of {@see \App\Schedule}, listed in `app.tenantless_messages`.
 */
final class RetentionHandler
{
    private const string FAILED_MESSAGE_RETENTION = '-30 days';

    public function __construct(
        #[Autowire(service: 'rate_limiter.cache')]
        private readonly PruneableInterface $rateLimiterCache,
        private readonly FailedMessageSql $failedMessages,
        private readonly ClockInterface $clock,
    ) {
    }

    #[AsMessageHandler]
    public function pruneRateLimiterCache(PruneRateLimiterCacheJob $job): void
    {
        $this->rateLimiterCache->prune();
    }

    #[AsMessageHandler]
    public function purgeFailedMessages(PurgeFailedMessagesJob $job): void
    {
        $this->failedMessages->deleteCreatedBefore($this->clock->now()->modify(self::FAILED_MESSAGE_RETENTION));
    }
}
