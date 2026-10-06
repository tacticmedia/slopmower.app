<?php

declare(strict_types=1);

namespace App\Tests\Support\EventStore;

use App\Domain\Event\RecordedEvent;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Entity\ReviewEvent;
use App\Repository\ReviewEventRepository;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The stored events of a review, for KernelTestCase tests.
 */
trait EventLog
{
    /**
     * Sets the tenant context and clears the entity manager after the read.
     *
     * @return list<RecordedEvent> In seq order
     */
    private static function eventLog(TenantId $tenantId, ReviewId $reviewId): array
    {
        $container = self::getContainer();
        $container->get(TenantContext::class)->set($tenantId);
        $events = array_map(
            static fn (ReviewEvent $event): RecordedEvent => $event->toRecordedEvent(),
            $container->get(ReviewEventRepository::class)->findBy(['reviewId' => $reviewId], ['seq' => 'ASC']),
        );
        $container->get(EntityManagerInterface::class)->clear();

        return $events;
    }
}
