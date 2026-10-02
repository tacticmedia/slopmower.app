<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Anchor\AnchorKind;
use App\Domain\Event\Codec\PayloadJson;
use App\Domain\Event\EventType;
use App\Domain\Event\RequestOpenedEvent;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ArchitectureExamplePayloadTest extends KernelTestCase
{
    #[TestDox('Decodes the request.opened example of architecture section 3')]
    public function testDecodesTheDocumentedExample(): void
    {
        $architecture = (string) file_get_contents(dirname(__DIR__, 4).'/docs/architecture.md');
        self::assertSame(1, preg_match('/Example `request\.opened` payload:\s*```json\n(.+?)\n```/s', $architecture, $match));
        $payload = PayloadJson::decode($match[1]);

        $event = EventType::RequestOpened->decode($payload);

        self::assertInstanceOf(RequestOpenedEvent::class, $event);
        self::assertSame(AnchorKind::Symbol, $event->anchor->kind);
        self::assertSame('App\Billing\InvoiceTotals::total()', $event->anchor->symbol?->fqn);
        self::assertSame(12, $event->anchor->snapshot?->lineCount);
        self::assertSame('01K6B3P1Z8Y7X6W5V4T3S2R1Q0', $event->batchId?->toString());
        self::assertEquals($payload, $event->toPayload());
    }
}
