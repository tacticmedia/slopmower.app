<?php

declare(strict_types=1);

namespace App\Messenger;

use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Messenger resets the services only after a message. In debug mode, the Doctrine query log, the traced event dispatcher,
 * the stopwatch, and other services keep data of each idle poll until the next reset.
 */
#[When('dev')]
#[AsEventListener]
final class IdleWorkerResetListener
{
    public function __construct(private readonly ServicesResetterInterface $servicesResetter)
    {
    }

    public function __invoke(WorkerRunningEvent $event): void
    {
        if ($event->isWorkerIdle()) {
            $this->servicesResetter->reset();
        }
    }
}
