<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\Messenger\QueuedMessages;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Console\Messenger\RunCommandMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\RedispatchMessageHandler;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class QueuedMessagesTest extends KernelTestCase
{
    use QueuedMessages;

    #[TestDox('Running the queue handles each queued message once, also the messages that a handler queues')]
    public function testRunQueuedMessagesDrainsTheQueue(): void
    {
        // The handler of RedispatchMessage queues the RunCommandMessage on async.
        self::asyncTransport()->send(new Envelope(new RedispatchMessage(new RunCommandMessage('list'), 'async')));

        self::runQueuedMessages();

        $handlerNames = array_map(
            static fn (Envelope $envelope): ?string => $envelope->last(HandledStamp::class)?->getHandlerName(),
            self::asyncTransport()->getAcknowledged(),
        );
        self::assertSame([RedispatchMessageHandler::class.'::__invoke', RunCommandMessageHandler::class.'::__invoke'], $handlerNames);
    }
}
