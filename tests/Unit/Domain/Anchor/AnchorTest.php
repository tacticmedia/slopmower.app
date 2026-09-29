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
use App\Tests\Unit\Domain\SpecFiles;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AnchorTest extends KernelTestCase
{
    use ViolatedFields;

    #[TestDox('Builds a file, line, range, and symbol anchor')]
    public function testBuildsEachKind(): void
    {
        $range = LineRange::of(38, 49);
        $symbol = SymbolRef::php(SymbolKind::Method, 'App\Billing\InvoiceTotals::total()');

        $file = Anchor::file(self::path(), self::commitSha(), self::blobSha());
        $line = Anchor::line(self::path(), self::commitSha(), self::blobSha(), LineRange::singleLine(7), self::snapshot(1));
        $region = Anchor::range(self::path(), self::commitSha(), self::blobSha(), $range, self::snapshot(12));
        $method = Anchor::symbol(self::path(), self::commitSha(), self::blobSha(), $range, $symbol, self::snapshot(12));

        self::assertSame([AnchorKind::File, null, null, null], [$file->kind, $file->range, $file->symbol, $file->snapshot]);
        self::assertSame([AnchorKind::Line, 7, null], [$line->kind, $line->range?->endLine, $line->symbol]);
        self::assertSame([AnchorKind::Range, $range, null, 12], [$region->kind, $region->range, $region->symbol, $region->snapshot?->lineCount]);
        self::assertSame([AnchorKind::Symbol, $range, $symbol], [$method->kind, $method->range, $method->symbol]);
        self::assertTrue($method->equals(Anchor::fromParts(AnchorKind::Symbol, self::path(), self::commitSha(), self::blobSha(), LineRange::of(38, 49), $symbol, self::snapshot(12))));
        self::assertFalse($method->equals($region));
        self::assertFalse($file->equals(Anchor::file(RepoPath::fromString('src/Other.php'), self::commitSha(), self::blobSha())));
    }

    #[DataProvider('fixtures')]
    #[TestDox('Rebuilds the anchor of the request fixture')]
    public function testRebuildsFixtureAnchor(string $fixture): void
    {
        $fixtureAnchor = SpecFiles::fixture($fixture)['anchor'];
        $range = $fixtureAnchor['range'];
        $symbol = $fixtureAnchor['symbol'];
        $snapshot = $fixtureAnchor['snapshot'];

        $anchor = Anchor::fromParts(
            AnchorKind::from($fixtureAnchor['kind']),
            RepoPath::fromString($fixtureAnchor['path']),
            CommitSha::fromString($fixtureAnchor['commitSha']),
            BlobSha::fromString($fixtureAnchor['blobSha']),
            LineRange::of($range['startLine'], $range['endLine']),
            null === $symbol ? null : SymbolRef::fromParts($symbol['language'], SymbolKind::from($symbol['kind']), $symbol['fqn']),
            ContentSnapshot::fromParts($snapshot['lines'], $snapshot['truncated'], $snapshot['lineCount'], $snapshot['before'], $snapshot['after'], $snapshot['normalizedHash']),
        );

        self::assertSame(
            [$fixtureAnchor['kind'], $fixtureAnchor['path'], $symbol['fqn'] ?? null, $snapshot['normalizedHash']],
            [$anchor->kind->value, $anchor->path->toString(), $anchor->symbol?->fqn, $anchor->snapshot?->normalizedHash],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        yield 'range' => ['request.opened-range.json'];
        yield 'symbol' => ['request.in-progress.json'];
    }

    /**
     * @param list<string> $expectedFields
     */
    #[DataProvider('invalidAnchors')]
    #[TestDox('Enforces range, symbol, and snapshot rules per kind')]
    public function testEnforcesRulesPerKind(AnchorKind $kind, ?LineRange $range, ?SymbolRef $symbol, ?ContentSnapshot $snapshot, array $expectedFields): void
    {
        self::assertSame(
            $expectedFields,
            self::violatedFields(static fn (): Anchor => Anchor::fromParts($kind, self::path(), self::commitSha(), self::blobSha(), $range, $symbol, $snapshot)),
        );
    }

    /**
     * @return iterable<string, array{AnchorKind, ?LineRange, ?SymbolRef, ?ContentSnapshot, list<string>}>
     */
    public static function invalidAnchors(): iterable
    {
        $oneLine = LineRange::singleLine(5);
        $twoLines = LineRange::of(5, 6);
        $symbol = SymbolRef::php(SymbolKind::Function, 'App\format');

        yield 'file with a range' => [AnchorKind::File, $oneLine, null, null, ['anchor.range']];
        yield 'file with a symbol' => [AnchorKind::File, null, $symbol, null, ['anchor.symbol']];
        yield 'file with a snapshot' => [AnchorKind::File, null, null, self::snapshot(1), ['anchor.snapshot']];
        yield 'line without a range' => [AnchorKind::Line, null, null, self::snapshot(1), ['anchor.range']];
        yield 'line over two lines' => [AnchorKind::Line, $twoLines, null, self::snapshot(2), ['anchor.range.endLine']];
        yield 'line without a snapshot' => [AnchorKind::Line, $oneLine, null, null, ['anchor.snapshot']];
        yield 'line with a symbol' => [AnchorKind::Line, $oneLine, $symbol, self::snapshot(1), ['anchor.symbol']];
        yield 'range over one line' => [AnchorKind::Range, $oneLine, null, self::snapshot(1), ['anchor.range.endLine']];
        yield 'range with a symbol' => [AnchorKind::Range, $twoLines, $symbol, self::snapshot(2), ['anchor.symbol']];
        yield 'line with a snapshot of other length' => [AnchorKind::Line, $oneLine, null, self::snapshot(2), ['anchor.snapshot.lineCount']];
        yield 'range with a snapshot of other length' => [AnchorKind::Range, $twoLines, null, self::snapshot(3), ['anchor.snapshot.lineCount']];
        yield 'symbol without a symbol' => [AnchorKind::Symbol, $oneLine, null, self::snapshot(1), ['anchor.symbol']];
        yield 'symbol without a range' => [AnchorKind::Symbol, null, $symbol, self::snapshot(1), ['anchor.range']];
        yield 'symbol without a snapshot' => [AnchorKind::Symbol, $oneLine, $symbol, null, ['anchor.snapshot']];
        yield 'symbol with a snapshot of other length' => [AnchorKind::Symbol, $twoLines, $symbol, self::snapshot(3), ['anchor.snapshot.lineCount']];
        yield 'symbol with nothing' => [AnchorKind::Symbol, null, null, null, ['anchor.range', 'anchor.snapshot', 'anchor.symbol']];
    }

    private static function snapshot(int $lineCount): ContentSnapshot
    {
        return ContentSnapshot::fromParts(array_fill(0, $lineCount, 'code'), false, $lineCount, [], [], 'sha256:'.str_repeat('0', 64));
    }

    private static function path(): RepoPath
    {
        return RepoPath::fromString('src/Billing/InvoiceTotals.php');
    }

    private static function commitSha(): CommitSha
    {
        return CommitSha::fromString('79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091');
    }

    private static function blobSha(): BlobSha
    {
        return BlobSha::fromString('3b18e512dba79e4c8300dd08aeb37f8e728b8dad');
    }
}
