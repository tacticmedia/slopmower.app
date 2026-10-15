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
    /** A failed envelope can hold a whole webhook payload and a stack trace. */
    public const int BATCH_SIZE = 100;

    private const string DELETE_BATCH_CREATED_BEFORE = <<<'SQL'
        WITH deleted AS (
            DELETE FROM messenger_messages
            WHERE id IN (SELECT id FROM messenger_messages WHERE queue_name = 'failed' AND created_at < :cutoff ORDER BY id LIMIT :batchSize)
            RETURNING id, body
        )
        SELECT id, body FROM deleted ORDER BY id
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Deletes the {@see self::BATCH_SIZE} oldest messages created before the cutoff.
     *
     * @return list<array{id: int, body: string}> The deleted messages, with the encoded envelope as body
     */
    public function deleteBatchCreatedBefore(\DateTimeImmutable $cutoff): array
    {
        // The transport writes `created_at` as UTC wall time.
        $cutoffUtc = $cutoff->setTimezone(new \DateTimeZone('UTC'));

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'body' => (string) $row['body']],
            $this->connection->fetchAllAssociative(
                self::DELETE_BATCH_CREATED_BEFORE,
                ['cutoff' => $cutoffUtc, 'batchSize' => self::BATCH_SIZE],
                ['cutoff' => Types::DATETIME_IMMUTABLE, 'batchSize' => Types::INTEGER],
            ),
        );
    }
}
