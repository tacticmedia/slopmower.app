<?php

declare(strict_types=1);

namespace App\Webhook;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * The duplicate check of docs/specs/webhooks-and-live-updates.md section 1. Raw SQL, because the ORM has no
 * `ON CONFLICT DO NOTHING`, and two deliveries of one ID can run at the same time.
 */
final class WebhookDeliverySql
{
    private const string IS_RECORDED = 'SELECT 1 FROM webhook_delivery WHERE id = :id';
    private const string RECORD = 'INSERT INTO webhook_delivery (id, received_at) VALUES (:id, :receivedAt) ON CONFLICT (id) DO NOTHING';
    private const string PURGE = 'DELETE FROM webhook_delivery WHERE received_at < :cutoff';
    private const string RETENTION = '-7 days';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function isRecorded(string $deliveryId): bool
    {
        return false !== $this->connection->fetchOne(self::IS_RECORDED, ['id' => $deliveryId]);
    }

    /**
     * Also deletes the deliveries older than 7 days.
     */
    public function record(string $deliveryId, \DateTimeImmutable $receivedAt): void
    {
        $this->connection->executeStatement(self::RECORD, ['id' => $deliveryId, 'receivedAt' => $receivedAt], ['receivedAt' => Types::DATETIME_IMMUTABLE]);
        $this->connection->executeStatement(self::PURGE, ['cutoff' => $receivedAt->modify(self::RETENTION)], ['cutoff' => Types::DATETIME_IMMUTABLE]);
    }
}
