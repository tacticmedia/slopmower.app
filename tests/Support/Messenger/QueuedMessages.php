<?php

declare(strict_types=1);

namespace App\Tests\Support\Messenger;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

trait QueuedMessages
{
    private static function asyncTransport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * Handles the queue as the worker does, until it is empty, so messages that handlers queue run too.
     * The ReceivedStamp keeps SendMessageMiddleware from sending the envelope back to the transport.
     */
    private static function runQueuedMessages(): void
    {
        $transport = self::asyncTransport();
        $bus = static::getContainer()->get(MessageBusInterface::class);

        while ([] !== $envelopes = [...$transport->get()]) {
            foreach ($envelopes as $envelope) {
                $transport->ack($bus->dispatch($envelope->with(new ReceivedStamp('async'))));
            }
        }
    }
}
