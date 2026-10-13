<?php

declare(strict_types=1);

namespace App\Messenger;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * The queue `failed` of the Doctrine transport (config/packages/messenger.yaml).
 */
final class FailedMessageSql
{
    private const string DELETE_CREATED_BEFORE = "DELETE FROM messenger_messages WHERE queue_name = 'failed' AND created_at < :cutoff";

    public function __construct(private readonly Connection $connection)
    {
    }

    public function deleteCreatedBefore(\DateTimeImmutable $cutoff): void
    {
        // The transport writes `created_at` as UTC wall time.
        $cutoffUtc = $cutoff->setTimezone(new \DateTimeZone('UTC'));

        $this->connection->executeStatement(self::DELETE_CREATED_BEFORE, ['cutoff' => $cutoffUtc], ['cutoff' => Types::DATETIME_IMMUTABLE]);
    }
}
