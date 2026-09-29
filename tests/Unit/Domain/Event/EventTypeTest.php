<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Event\EventType;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EventTypeTest extends KernelTestCase
{
    #[TestDox('Only request events have a requestId in the envelope')]
    public function testOnlyRequestEventsHaveARequestId(): void
    {
        $typesWithRequestId = array_values(array_filter(EventType::cases(), static fn (EventType $type): bool => $type->hasRequestId()));

        self::assertSame(
            [
                EventType::RequestOpened,
                EventType::RequestCommented,
                EventType::RequestClaimed,
                EventType::RequestReleased,
                EventType::RequestReadyForReview,
                EventType::RequestWontDo,
                EventType::RequestVerified,
                EventType::RequestReopened,
            ],
            $typesWithRequestId,
        );
    }
}
