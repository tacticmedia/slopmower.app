<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Review\IndexState;
use App\Domain\Review\ReviewState;
use App\Message\IndexReview;
use App\Message\QueueStalledIndexesJob;
use App\Messenger\TenantStamp;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Ulid;

/**
 * D95: the hourly job of {@see \App\Schedule}, listed in `app.tenantless_messages`. Queues the index job again for a
 * review whose job was lost between the commit of review.started and the dispatch.
 */
#[AsMessageHandler]
final class StalledIndexHandler
{
    private const string STALLED_AFTER = '-1 hour';

    public function __construct(
        private readonly Connection $connection,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(QueueStalledIndexesJob $job): void
    {
        $stalled = $this->connection->fetchAllAssociative(
            'SELECT tenant_id, id FROM review WHERE state = ? AND index_state = ? AND created_at < ?',
            [ReviewState::Open->value, IndexState::Pending->value, $this->clock->now()->modify(self::STALLED_AFTER)],
            [Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE],
        );

        foreach ($stalled as $review) {
            $this->bus->dispatch(new IndexReview(ReviewId::fromUlid(Ulid::fromString((string) $review['id']))), [new TenantStamp(TenantId::fromUlid(Ulid::fromString((string) $review['tenant_id'])))]);
        }
    }
}
