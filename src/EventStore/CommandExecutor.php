<?php

declare(strict_types=1);

namespace App\EventStore;

use App\Domain\Actor\Actor;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\RecordedEvent;
use App\Domain\Identity\EventId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Entity\ReviewEvent;
use App\Projection\RequestProjector;
use App\Projection\ReviewProjector;
use App\Repository\CommentRepository;
use App\Repository\CommitLinkRepository;
use App\Repository\ReviewEventRepository;
use App\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The only write path for events and projections. One transaction per call, under the lock of the tenant row.
 */
final class CommandExecutor
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventStoreSql $eventStore,
        private readonly ReviewEventRepository $events,
        private readonly CommentRepository $comments,
        private readonly CommitLinkRepository $links,
        private readonly RequestProjector $requestProjector,
        private readonly ReviewProjector $reviewProjector,
        private readonly TenantContext $tenantContext,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param \Closure(): list<DecidedEvent>             $decide        Reads the state under the lock, with the findFresh() repository methods
     * @param (\Closure(list<RecordedEvent>): void)|null $inTransaction The other writes of the command, after the append and before the projectors
     *
     * @return list<RecordedEvent>
     */
    public function execute(ReviewId $reviewId, Actor $actor, \Closure $decide, ?\Closure $inTransaction = null): array
    {
        $tenantId = $this->tenantContext->get();

        $recorded = $this->transactional($tenantId, function (int $eventSeq) use ($tenantId, $reviewId, $actor, $decide, $inTransaction): array {
            // Times are stored with second precision, so the projection equals what a reload returns.
            $occurredAt = new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
            $recorded = [];

            foreach ($decide() as $decided) {
                $recorded[] = new RecordedEvent(EventId::generate(), $tenantId, ++$eventSeq, $reviewId, $decided->requestId, $actor, $occurredAt, $decided->event);
            }

            $this->eventStore->append($recorded);

            if (null !== $inTransaction) {
                $inTransaction($recorded);
            }

            $this->entityManager->flush();
            $this->project($recorded);

            return $recorded;
        });

        if ([] !== $recorded) {
            $this->dispatcher->dispatch(new EventsCommitted($tenantId, $reviewId, $recorded));
        }

        return $recorded;
    }

    /**
     * Deletes and replays `comment` and `commit_link`. Recomputes `change_request` and the event columns of `review`
     * in place, so the data that is not event data stays. Clears the entity manager.
     *
     * @return int The number of replayed events
     */
    public function rebuildProjections(): int
    {
        $tenantId = $this->tenantContext->get();

        $count = $this->transactional($tenantId, function () use ($tenantId): int {
            $this->comments->deleteOfTenant($tenantId);
            $this->links->deleteOfTenant($tenantId);
            // The bulk deletes bypass the identity map.
            $this->entityManager->clear();
            $events = array_map(
                static fn (ReviewEvent $event): RecordedEvent => $event->toRecordedEvent(),
                $this->events->findBy(['tenantId' => Ulid::fromString($tenantId->toString())], ['seq' => 'ASC']),
            );
            $this->project($events);

            return count($events);
        });

        $this->entityManager->clear();

        return $count;
    }

    /**
     * Deletes events and projections with the rows that belong to them, under the tenant row lock, so no command of
     * the tenant writes meanwhile. Clears the entity manager, because raw deletes bypass the identity map.
     *
     * @param \Closure(): void $delete
     */
    public function delete(TenantId $tenantId, \Closure $delete): void
    {
        $this->transactional($tenantId, $delete);
        $this->entityManager->clear();
    }

    /**
     * Refuses an open transaction, so {@see EventsCommitted} always follows a real commit. On any exception it rolls
     * back and clears the entity manager, so no changed entity stays in the identity map.
     *
     * @template T
     *
     * @param \Closure(int): T $work Gets the locked event_seq
     *
     * @return T
     */
    private function transactional(TenantId $tenantId, \Closure $work): mixed
    {
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('The command executor runs only outside a database transaction.');
        }

        $this->connection->beginTransaction();

        try {
            $result = $work($this->eventStore->lockTenant($tenantId));
            $this->connection->commit();

            return $result;
        } catch (\Throwable $error) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $this->entityManager->clear();

            throw $error;
        }
    }

    /**
     * Flushes after each event, because the projectors read the stored rows.
     *
     * @param list<RecordedEvent> $events
     */
    private function project(array $events): void
    {
        foreach ($events as $event) {
            $this->requestProjector->project($event);
            $this->reviewProjector->project($event);
            $this->entityManager->flush();
        }
    }
}
