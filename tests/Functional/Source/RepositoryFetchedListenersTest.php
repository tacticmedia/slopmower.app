<?php

declare(strict_types=1);

namespace App\Tests\Functional\Source;

use App\Application\Event\RepositoryFetched;
use App\Application\QueueLocationRecompute;
use App\Application\ResolvePendingCommitLinks;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RepositoryFetchedListenersTest extends KernelTestCase
{
    #[TestDox('The recompute is queued before pending links resolve')]
    public function testRecomputeIsQueuedFirst(): void
    {
        $listeners = array_map(
            static fn (mixed $listener): string => \is_array($listener) ? get_debug_type($listener[0]).'::'.$listener[1] : get_debug_type($listener),
            self::getContainer()->get('event_dispatcher')->getListeners(RepositoryFetched::class),
        );

        self::assertSame([QueueLocationRecompute::class.'::onRepositoryFetched', ResolvePendingCommitLinks::class.'::onRepositoryFetched'], $listeners);
    }
}
