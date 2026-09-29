<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Event\CommitUnlinked;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\EventType;
use App\Domain\Event\RequestClaimed;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DecidedEventTest extends KernelTestCase
{
    #[TestDox('Carries a requestId exactly for request event types')]
    public function testCarriesARequestIdExactlyForRequestEvents(): void
    {
        $requestId = RequestId::generate();
        $unlinked = new CommitUnlinked(CommitSha::fromString('a1b2c3d4e5f60718293a4b5c6d7e8f9012345678'), $requestId);

        self::assertSame([$requestId, EventType::RequestClaimed], [DecidedEvent::forRequest($requestId, new RequestClaimed())->requestId, DecidedEvent::forRequest($requestId, new RequestClaimed())->type()]);
        self::assertNull(DecidedEvent::withoutRequest($unlinked)->requestId);
        foreach ([static fn (): DecidedEvent => DecidedEvent::withoutRequest(new RequestClaimed()), static fn (): DecidedEvent => DecidedEvent::forRequest($requestId, $unlinked)] as $build) {
            try {
                $build();
                self::fail('The requestId rule was not enforced.');
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
