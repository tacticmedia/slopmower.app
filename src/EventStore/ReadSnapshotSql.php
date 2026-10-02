<?php

declare(strict_types=1);

namespace App\EventStore;

use App\Domain\Identity\TenantId;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;

/**
 * A read-only REPEATABLE READ transaction. Every event with a seq up to the event_seq read in it is committed and
 * visible, because {@see EventStoreSql} numbers events under the tenant row lock in commit order.
 */
final class ReadSnapshotSql
{
    private const string BEGIN_SNAPSHOT = 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY';
    private const string EVENT_SEQ = 'SELECT event_seq FROM tenant WHERE id = :tenantId';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Read with queries that refresh managed entities, because the identity map may hold rows from before the snapshot.
     *
     * @template T
     *
     * @param \Closure(int): T $read Gets the event_seq of the tenant
     *
     * @return T
     */
    public function read(TenantId $tenantId, \Closure $read): mixed
    {
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('A snapshot read starts its own transaction.');
        }

        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement(self::BEGIN_SNAPSHOT);
            $eventSeq = $this->connection->fetchOne(self::EVENT_SEQ, ['tenantId' => Ulid::fromString($tenantId->toString())->toRfc4122()]);
            $result = $read(false === $eventSeq ? throw new \LogicException("The tenant {$tenantId} has no row.") : (int) $eventSeq);
            $this->connection->commit();

            return $result;
        } catch (\Throwable $error) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $error;
        }
    }
}
