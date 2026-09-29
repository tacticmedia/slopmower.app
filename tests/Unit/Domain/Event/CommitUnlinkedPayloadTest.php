<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Event\Codec\EventCodec;
use App\Domain\Event\CommitUnlinked;
use App\Domain\Event\EventType;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommitUnlinkedPayloadTest extends KernelTestCase
{
    #[TestDox('Carries the commit SHA and the request ID')]
    public function testCarriesTheCommitShaAndTheRequestId(): void
    {
        $fixture = EventFixtures::json(EventType::CommitUnlinked);

        $event = EventCodec::decode('commit.unlinked', $fixture);

        self::assertEquals(
            new CommitUnlinked(CommitSha::fromString('e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e'), RequestId::fromString('01K6B3P2A9QRSTVWXYZ0123457')),
            $event,
        );
        self::assertSame($fixture, EventCodec::encode($event));
    }
}
