<?php

declare(strict_types=1);

namespace App\EventStore;

use App\Domain\Event\Codec\PayloadJson;
use App\Domain\Event\RecordedEvent;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\TenantId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Inserts with SQL, so an empty payload stays "{}".
 */
final class EventStoreSql
{
    private const string LOCK_TENANT = 'SELECT event_seq FROM tenant WHERE id = :tenantId FOR UPDATE';
    private const string UPDATE_EVENT_SEQ = 'UPDATE tenant SET event_seq = :eventSeq WHERE id = :tenantId';
    private const string INSERT_EVENT = 'INSERT INTO review_event (id, tenant_id, seq, review_id, request_id, request_ids, type, actor_kind, actor_user_id, actor_agent_name, occurred_at, payload)'
        .' VALUES (:id, :tenantId, :seq, :reviewId, :requestId, :requestIds, :type, :actorKind, :actorUserId, :actorAgentName, :occurredAt, :payload)';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Holds the row lock until the transaction ends, so one writer per tenant numbers events at a time.
     *
     * @return int The last event number of the tenant
     */
    public function lockTenant(TenantId $tenantId): int
    {
        $eventSeq = $this->connection->fetchOne(self::LOCK_TENANT, ['tenantId' => $tenantId->toRfc4122()]);

        return false === $eventSeq ? throw new \LogicException("The tenant {$tenantId} has no row.") : (int) $eventSeq;
    }

    /**
     * @param list<RecordedEvent> $events Numbered without gaps from the locked event_seq + 1
     */
    public function append(array $events): void
    {
        foreach ($events as $event) {
            $actor = $event->actor;

            $this->connection->executeStatement(self::INSERT_EVENT, [
                'id' => $event->id->toRfc4122(),
                'tenantId' => $event->tenantId->toRfc4122(),
                'seq' => $event->seq,
                'reviewId' => $event->reviewId->toRfc4122(),
                'requestId' => $event->requestId?->toRfc4122(),
                'requestIds' => json_encode(array_map(static fn (RequestId $id): string => $id->toString(), $event->requestIds()), \JSON_THROW_ON_ERROR),
                'type' => $event->type->value,
                'actorKind' => $actor->kind->value,
                'actorUserId' => $actor->userId?->toRfc4122(),
                'actorAgentName' => $actor->agentName,
                'occurredAt' => $event->occurredAt,
                'payload' => PayloadJson::encode($event->event->toPayload()),
            ], ['occurredAt' => Types::DATETIMETZ_IMMUTABLE]);
        }

        $last = array_last($events);

        if (null !== $last) {
            $this->connection->executeStatement(self::UPDATE_EVENT_SEQ, ['eventSeq' => $last->seq, 'tenantId' => $last->tenantId->toRfc4122()]);
        }
    }
}
