<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\ContentSnapshot;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SymbolKind;
use App\Domain\Anchor\SymbolRef;
use App\Domain\Event\Codec\EventCodec;
use App\Domain\Event\RequestOpened;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\BatchId;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ArchitectureExamplePayloadTest extends KernelTestCase
{
    #[TestDox('Decodes the request.opened example of architecture section 3')]
    public function testDecodesTheRequestOpenedExample(): void
    {
        $example = self::projectFile('tests/Fixtures/events/architecture-request-opened-example.json');
        self::assertStringContainsString($example, self::projectFile('docs/architecture.md'), 'The fixture must equal the example in docs/architecture.md.');

        $event = EventCodec::decode('request.opened', $example);

        self::assertEquals(new RequestOpened(
            Anchor::symbol(
                RepoPath::fromString('src/Billing/InvoiceTotals.php'),
                CommitSha::fromString('79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091'),
                BlobSha::fromString('3b18e512dba79e4c8300dd08aeb37f8e728b8dad'),
                LineRange::of(38, 49),
                SymbolRef::php(SymbolKind::Method, 'App\Billing\InvoiceTotals::total()'),
                ContentSnapshot::fromParts(
                    [
                        '    /**',
                        '     * Sum of all invoice lines.',
                        '     */',
                        '    public function total(): float',
                        '    {',
                        '        $sum = 0.0;',
                        '        foreach ($this->lines as $line) {',
                        '            $sum += $line->amount;',
                        '        }',
                        '',
                        '        return $sum;',
                        '    }',
                    ],
                    false,
                    12,
                    ['    }', '', ''],
                    ['', '    public function tax(): float', '    {'],
                    'sha256:4cd0f94b186ef4903963fa0397ae6175cd9f82d31d4512d8e9b67a2b3a0142f9',
                ),
            ),
            'Use `Money` instead of `float` for totals.',
            BatchId::fromString('01K6B3P1Z8Y7X6W5V4T3S2R1Q0'),
        ), $event);
    }

    private static function projectFile(string $pathInProject): string
    {
        $contents = file_get_contents(\dirname(__DIR__, 4).'/'.$pathInProject);

        return false !== $contents ? $contents : throw new \RuntimeException('Cannot read '.$pathInProject);
    }
}
