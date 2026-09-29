<?php

declare(strict_types=1);

namespace App\Tests\Functional\Messenger;

use App\Tests\Support\Messenger\QueuedMessages;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Messenger\RunCommandContext;
use Symfony\Component\Console\Messenger\RunCommandMessage;
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
        $queued = self::getContainer()->get(MessageBusInterface::class)->dispatch($message, [new TransportNamesStamp(['async'])]);
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
}
