<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Actor\Actor;
use App\Domain\Event\CommitUnlinkedEvent;
use App\Domain\Event\DomainEvent;
use App\Domain\Event\EventType;
use App\Domain\Event\RecordedEvent;
use App\Domain\Event\RequestClaimedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecordedEventTest extends KernelTestCase
{
    #[TestDox('Requires a requestId exactly for request events')]
    public function testRequiresRequestIdExactlyForRequestEvents(): void
    {
        $requestId = RequestId::generate();
        $unlinked = new CommitUnlinkedEvent(CommitSha::fromString('a1b2c3d4e5f60718293a4b5c6d7e8f9012345678'), $requestId);

        self::assertSame(EventType::RequestClaimed, self::record($requestId, new RequestClaimedEvent())->type);
        self::assertSame(EventType::CommitUnlinked, self::record(null, $unlinked)->type);
        self::assertLogicException(static fn () => self::record(null, new RequestClaimedEvent()));
        self::assertLogicException(static fn () => self::record($requestId, $unlinked));
    }

    private static function assertLogicException(callable $build): void
    {
        try {
            $build();
        } catch (\LogicException) {
            return;
        }

        self::fail('No LogicException was thrown.');
    }

    private static function record(?RequestId $requestId, DomainEvent $event): RecordedEvent
    {
        return new RecordedEvent(
            EventId::generate(),
            TenantId::generate(),
            1,
            ReviewId::generate(),
            $requestId,
            Actor::agent(UserId::generate(), 'claude-code laptop'),
            new \DateTimeImmutable('2026-09-29T07:45:00Z'),
            $event,
        );
    }
}
