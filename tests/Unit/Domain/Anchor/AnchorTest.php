<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Anchor;

use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Anchor\ContentSnapshot;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SymbolKind;
use App\Domain\Anchor\SymbolRef;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AnchorTest extends KernelTestCase
{
    use InvalidInputAssertions;

    #[TestDox('Accepts each kind with exactly its fields')]
    #[DataProvider('validFields')]
    public function testAcceptsEachKind(AnchorKind $kind, ?LineRange $range, ?SymbolRef $symbol, ?ContentSnapshot $snapshot): void
    {
        $anchor = self::anchor($kind, $range, $symbol, $snapshot);

        self::assertSame($kind, $anchor->kind);
        self::assertTrue($anchor->equals(self::anchor($kind, $range, $symbol, $snapshot)));
    }

    /**
     * @return iterable<string, array{AnchorKind, ?LineRange, ?SymbolRef, ?ContentSnapshot}>
     */
    public static function validFields(): iterable
    {
        yield 'file' => [AnchorKind::File, null, null, null];
        yield 'line' => [AnchorKind::Line, LineRange::of(3, 3), null, self::snapshot(1)];
        yield 'range' => [AnchorKind::Range, LineRange::of(52, 58), null, self::snapshot(7)];
        yield 'symbol' => [AnchorKind::Symbol, LineRange::of(38, 49), self::symbol(), self::snapshot(12)];
    }

    /**
     * @param list<string> $expectedFields
     */
    #[TestDox('Enforces range, symbol, and snapshot rules per kind')]
    #[DataProvider('invalidFields')]
    public function testEnforcesRulesPerKind(AnchorKind $kind, ?LineRange $range, ?SymbolRef $symbol, ?ContentSnapshot $snapshot, array $expectedFields): void
    {
        self::assertInvalidInput($expectedFields, static fn () => self::anchor($kind, $range, $symbol, $snapshot));
    }

    /**
     * @return iterable<string, array{AnchorKind, ?LineRange, ?SymbolRef, ?ContentSnapshot, list<string>}>
     */
    public static function invalidFields(): iterable
    {
        yield 'file with range, symbol, and snapshot' => [AnchorKind::File, LineRange::of(1, 2), self::symbol(), self::snapshot(2), ['range', 'symbol', 'snapshot']];
        yield 'line without range and snapshot' => [AnchorKind::Line, null, null, null, ['range', 'snapshot']];
        yield 'line with a symbol' => [AnchorKind::Line, LineRange::of(3, 3), self::symbol(), self::snapshot(1), ['symbol']];
        yield 'line over two lines' => [AnchorKind::Line, LineRange::of(3, 4), null, self::snapshot(2), ['range.endLine']];
        yield 'range of one line' => [AnchorKind::Range, LineRange::of(3, 3), null, self::snapshot(1), ['range.endLine']];
        yield 'symbol without a symbol' => [AnchorKind::Symbol, LineRange::of(38, 49), null, self::snapshot(12), ['symbol']];
        yield 'snapshot of another line count' => [AnchorKind::Range, LineRange::of(52, 58), null, self::snapshot(6), ['snapshot.lineCount']];
    }

    private static function anchor(AnchorKind $kind, ?LineRange $range, ?SymbolRef $symbol, ?ContentSnapshot $snapshot): Anchor
    {
        return Anchor::of(
            $kind,
            RepoPath::fromString('src/Billing/InvoiceTotals.php'),
            CommitSha::fromString('79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091'),
            BlobSha::fromString('3b18e512dba79e4c8300dd08aeb37f8e728b8dad'),
            $range,
            $symbol,
            $snapshot,
        );
    }

    private static function snapshot(int $lineCount): ContentSnapshot
    {
        return ContentSnapshot::of(
            array_fill(0, $lineCount, 'x'),
            false,
            $lineCount,
            [],
            [],
            'sha256:4cd0f94b186ef4903963fa0397ae6175cd9f82d31d4512d8e9b67a2b3a0142f9',
        );
    }

    private static function symbol(): SymbolRef
    {
        return SymbolRef::of('php', SymbolKind::Method, 'App\\Billing\\InvoiceTotals::total()');
    }
}
