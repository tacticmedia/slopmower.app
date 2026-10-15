<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PruneRateLimiterCacheJob;
use App\Message\PurgeFailedMessagesJob;
use App\Messenger\FailedMessageSql;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Transport\Serialization\MessageTypeAwareSerializerInterface;

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
        // The inner serializer of the failure transport. Its SigningSerializer cannot read the message class without
        // unserializing the message.
        #[Autowire(service: 'messenger.transport.native_php_serializer')]
        private readonly MessageTypeAwareSerializerInterface $failedMessageSerializer,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function pruneRateLimiterCache(PruneRateLimiterCacheJob $job): void
    {
        $this->rateLimiterCache->prune();
    }

    /**
     * Logs each deleted batch at error, after its commit: nobody retried these messages in 30 days.
     */
    #[AsMessageHandler]
    public function purgeFailedMessages(PurgeFailedMessagesJob $job): void
    {
        $cutoff = $this->clock->now()->modify(self::FAILED_MESSAGE_RETENTION);

        while ([] !== $batch = $this->failedMessages->deleteBatchCreatedBefore($cutoff)) {
            $messages = array_map(
                fn (array $message): array => ['id' => $message['id'], 'class' => $this->failedMessageSerializer->getMessageType(['body' => $message['body']])],
                $batch,
            );
            unset($batch);

            $this->logger->error('The retention job deleted {count} failed messages older than 30 days.', ['count' => \count($messages), 'messages' => $messages]);
        }
    }
}
