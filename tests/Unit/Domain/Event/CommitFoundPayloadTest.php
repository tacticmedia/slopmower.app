<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Event\CommitFoundEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommitFoundPayloadTest extends KernelTestCase
{
    #[TestDox('Carries the commit SHA, the linked request IDs, the subject, and the commit time')]
    public function testCarriesTheGitDataOfTheLink(): void
    {
        $event = new CommitFoundEvent(
            CommitSha::fromString('e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e'),
            [RequestId::fromString('01K6B3P2A9QRSTVWXYZ0123456'), RequestId::fromString('01K6B3P2A9QRSTVWXYZ0123457')],
            'Extract TaxRounding',
            new \DateTimeImmutable('2026-09-29T18:02:31+10:00'),
        );

        self::assertSame([
            'commitSha' => 'e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e',
            'requestIds' => ['01K6B3P2A9QRSTVWXYZ0123456', '01K6B3P2A9QRSTVWXYZ0123457'],
            'subject' => 'Extract TaxRounding',
            'committedAt' => '2026-09-29T08:02:31Z',
        ], $event->toPayload());
    }
}
