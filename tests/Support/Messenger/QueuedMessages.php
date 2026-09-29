<?php

declare(strict_types=1);

namespace App\Tests\Support\Messenger;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Runs the messages that the test kernel queued on the in-memory `async` transport, with the stamps the worker adds.
 * The middleware runs. Worker events and their listeners do not.
 *
 * The next request of the same kernel empties the transport, so read and run the queue before it.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait QueuedMessages
{
    /**
     * @return list<object>
     */
    private static function queuedMessages(): array
    {
        return array_map(
            static fn (Envelope $envelope): object => $envelope->getMessage(),
            // get() returns one envelope unless it gets a fetch size.
            iterator_to_array(self::asyncTransport()->get(\PHP_INT_MAX), false),
        );
    }

    /**
     * Runs the queue until it is empty, including the messages that handlers queue.
     *
     * @return list<Envelope> the handled envelopes, in run order
     */
    private static function runQueuedMessages(): array
    {
        $transport = self::asyncTransport();
        $bus = self::getContainer()->get(MessageBusInterface::class);

        $handled = [];
        while ([] !== $envelopes = iterator_to_array($transport->get(), false)) {
            foreach ($envelopes as $envelope) {
                $handled[] = $bus->dispatch($envelope->with(new ReceivedStamp('async'), new ConsumedByWorkerStamp()));
                $transport->ack($envelope);
            }
        }

        return $handled;
    }

    private static function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
