<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\ReviewEvent;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * The event log is append-only. `readOnly` blocks only updates. A tenant purge deletes with SQL, not the ORM.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class EventLogGuard
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof ReviewEvent) {
                throw new \LogicException('The event log is append-only, so a review event cannot be deleted.');
            }
        }
    }
}
