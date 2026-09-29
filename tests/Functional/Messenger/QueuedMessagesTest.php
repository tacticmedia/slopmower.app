<?php

declare(strict_types=1);

namespace App\Tests\Functional\Messenger;

use App\Tests\Support\Messenger\QueuedMessages;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Messenger\RunCommandContext;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class QueuedMessagesTest extends KernelTestCase
{
    use QueuedMessages;

    #[TestDox('A queued message runs through its handler once, with the worker stamps, and leaves the queue empty')]
    public function testRunsQueuedMessageOnce(): void
    {
        $message = new RunCommandMessage('list --raw');
        $queued = self::queue($message);
        self::assertNull($queued->last(HandledStamp::class));
        self::assertSame([$message], self::queuedMessages());

        $handled = self::runQueuedMessages();

        self::assertCount(1, $handled);
        self::assertNotNull($handled[0]->last(ConsumedByWorkerStamp::class));
        $context = $handled[0]->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(RunCommandContext::class, $context);
        self::assertSame(0, $context->exitCode);
        self::assertSame([], self::queuedMessages());
    }

    #[TestDox('Lists every queued message in queue order')]
    public function testListsEveryQueuedMessage(): void
    {
        $first = new RunCommandMessage('list --raw');
        $second = new RunCommandMessage('about');
        self::queue($first);
        self::queue($second);

        self::assertSame([$first, $second], self::queuedMessages());
    }

    #[TestDox('Also runs the messages that handlers queue, until the queue is empty')]
    public function testRunsMessagesThatHandlersQueue(): void
    {
        $inner = new RunCommandMessage('list --raw');
        $outer = new RedispatchMessage($inner, 'async');
        self::queue($outer);
        self::assertSame([$outer], self::queuedMessages());

        $handled = self::runQueuedMessages();

        self::assertSame([$outer, $inner], array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $handled));
        self::assertSame([], self::queuedMessages());
    }

    private static function queue(object $message): Envelope
    {
        return self::getContainer()->get(MessageBusInterface::class)->dispatch($message, [new TransportNamesStamp(['async'])]);
    }
}
