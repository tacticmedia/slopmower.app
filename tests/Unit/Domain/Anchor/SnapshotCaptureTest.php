<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Anchor;

use App\Domain\Anchor\ContentSnapshot;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SnapshotCapture;
use App\Tests\Unit\Domain\SpecFiles;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @phpstan-type FixtureAnchor array{range: array{startLine: int, endLine: int}, snapshot: array{lines: list<string>, truncated: bool, lineCount: int, before: list<string>, after: list<string>, normalizedHash: string}}
 */
final class SnapshotCaptureTest extends KernelTestCase
{
    use ViolatedFields;

    private const array FIXTURES = ['request.opened-range.json', 'request.in-progress.json'];

    #[TestDox('Normalizes CRLF and trailing whitespace')]
    public function testNormalizesCrlfAndTrailingWhitespace(): void
    {
        $snapshot = SnapshotCapture::capture("  one \t\r\n\ttwo  \r\nthree\r\n  four \r\n", LineRange::of(2, 3));

        self::assertSame(["\ttwo", 'three'], $snapshot->lines);
        self::assertSame(['  one'], $snapshot->before);
        self::assertSame(['  four'], $snapshot->after);
        self::assertTrue($snapshot->equals(SnapshotCapture::capture("  one\n\ttwo\nthree\n  four", LineRange::of(2, 3))));
    }

    #[TestDox('Treats a final newline as the end of the last line')]
    public function testTreatsFinalNewlineAsEndOfLastLine(): void
    {
        self::assertSame(['a', 'b'], SnapshotCapture::fileLines("a\nb\n"));
        self::assertSame(['a', 'b'], SnapshotCapture::fileLines("a\r\nb"));
        self::assertSame(['a', ''], SnapshotCapture::fileLines("a\n\n"));
        self::assertSame([], SnapshotCapture::fileLines(''));
        self::assertTrue(SnapshotCapture::capture("a\nb\n", LineRange::of(1, 2))->equals(SnapshotCapture::capture("a\nb", LineRange::of(1, 2))));
    }

    #[DataProvider('rangesAfterTheLastLine')]
    #[TestDox('Rejects a range that ends after the last line')]
    public function testRejectsRangeAfterLastLine(string $fileContent, int $startLine, int $endLine): void
    {
        self::assertSame(
            ['anchor.range.endLine'],
            self::violatedFields(static fn (): ContentSnapshot => SnapshotCapture::capture($fileContent, LineRange::of($startLine, $endLine), 'anchor.range')),
        );
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function rangesAfterTheLastLine(): iterable
    {
        yield 'line after a final newline' => ["a\nb\n", 3, 3];
        yield 'range past the end' => ["a\nb", 2, 3];
        yield 'empty file' => ['', 1, 1];
    }

    #[TestDox('Keeps 200 lines and cuts 201 lines to the first and last 100 with lineCount 201')]
    public function testKeeps200LinesAndCuts201Lines(): void
    {
        $fileLines = array_map(static fn (int $number): string => 'line '.$number, range(1, 205));
        $fileContent = implode("\n", $fileLines)."\n";

        $kept = SnapshotCapture::capture($fileContent, LineRange::of(3, 202));
        $cut = SnapshotCapture::capture($fileContent, LineRange::of(2, 202));

        self::assertSame([\array_slice($fileLines, 2, 200), false, 200], [$kept->lines, $kept->truncated, $kept->lineCount]);
        self::assertSame(
            [[...\array_slice($fileLines, 1, 100), ...\array_slice($fileLines, 102, 100)], true, 201],
            [$cut->lines, $cut->truncated, $cut->lineCount],
        );
        self::assertSame(SnapshotCapture::normalizedHash(\array_slice($fileLines, 1, 201)), $cut->normalizedHash);
    }

    #[TestDox('Takes fewer context lines at the file edges')]
    public function testTakesFewerContextLinesAtFileEdges(): void
    {
        $fileContent = "1\n2\n3\n4\n5\n";

        self::assertContext([], ['2', '3', '4'], SnapshotCapture::capture($fileContent, LineRange::of(1, 1)));
        self::assertContext(['1', '2'], ['4', '5'], SnapshotCapture::capture($fileContent, LineRange::of(3, 3)));
        self::assertContext(['1'], ['5'], SnapshotCapture::capture($fileContent, LineRange::of(2, 4)));
        self::assertContext(['2', '3', '4'], [], SnapshotCapture::capture($fileContent, LineRange::of(5, 5)));
    }

    #[DataProvider('fixtures')]
    #[TestDox('Produces the normalizedHash of the snapshots in docs/specs/fixtures/request.opened-range.json and request.in-progress.json')]
    public function testProducesFixtureNormalizedHash(string $fixture): void
    {
        $snapshot = self::fixtureAnchor($fixture)['snapshot'];
        self::assertFalse($snapshot['truncated']);

        $capturedRegion = SnapshotCapture::capture(implode("\n", $snapshot['lines']), LineRange::of(1, $snapshot['lineCount']));

        self::assertSame($snapshot['normalizedHash'], SnapshotCapture::normalizedHash($snapshot['lines']));
        self::assertSame($snapshot['normalizedHash'], $capturedRegion->normalizedHash);
    }

    #[DataProvider('fixtures')]
    #[TestDox('Reproduces the fixture snapshots from the file text around their ranges')]
    public function testReproducesFixtureSnapshotsFromFileText(string $fixture): void
    {
        $anchor = self::fixtureAnchor($fixture);
        $expected = $anchor['snapshot'];

        $captured = SnapshotCapture::capture(self::fixtureFileText(), LineRange::of($anchor['range']['startLine'], $anchor['range']['endLine']));

        self::assertSame(
            [$expected['lines'], $expected['truncated'], $expected['lineCount'], $expected['before'], $expected['after'], $expected['normalizedHash']],
            [$captured->lines, $captured->truncated, $captured->lineCount, $captured->before, $captured->after, $captured->normalizedHash],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        foreach (self::FIXTURES as $fixture) {
            yield $fixture => [$fixture];
        }
    }

    #[TestDox('Gives the same hash for a re-indented region')]
    public function testGivesSameHashForReindentedRegion(): void
    {
        $original = SnapshotCapture::capture("    if (\$a) {\n        return \$b;\n    }\n", LineRange::of(1, 3));
        $reindented = SnapshotCapture::capture("\tif (\$a)   {\r\n\t\treturn \t\$b;\r\n\t}\r\n", LineRange::of(1, 3));
        $edited = SnapshotCapture::capture("    if (\$a) {\n        return \$c;\n    }\n", LineRange::of(1, 3));

        self::assertSame($original->normalizedHash, $reindented->normalizedHash);
        self::assertNotSame($original->normalizedHash, $edited->normalizedHash);
    }

    #[TestDox('Collapses only ASCII whitespace and accepts bytes that are not UTF-8')]
    public function testCollapsesOnlyAsciiWhitespace(): void
    {
        self::assertSame("\u{c5} \u{a0}x", SnapshotCapture::comparisonForm(" \t\u{c5}\x0B\x0C \u{a0}x \r"));
        self::assertSame("\xFF a", SnapshotCapture::comparisonForm("\xFF   a"));
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     */
    private static function assertContext(array $before, array $after, ContentSnapshot $snapshot): void
    {
        self::assertSame([$before, $after], [$snapshot->before, $snapshot->after]);
    }

    /**
     * @return FixtureAnchor
     */
    private static function fixtureAnchor(string $fixture): array
    {
        return SpecFiles::fixture($fixture)['anchor'];
    }

    /**
     * Both fixtures anchor in the same blob. Their snapshot and context lines give the file text around both ranges.
     */
    private static function fixtureFileText(): string
    {
        $textByLine = [];
        foreach (self::FIXTURES as $fixture) {
            $anchor = self::fixtureAnchor($fixture);
            $snapshot = $anchor['snapshot'];
            $firstLine = $anchor['range']['startLine'] - \count($snapshot['before']);
            foreach ([...$snapshot['before'], ...$snapshot['lines'], ...$snapshot['after']] as $offset => $text) {
                $line = $firstLine + $offset;
                self::assertSame($textByLine[$line] ?? $text, $text, 'The fixtures disagree on line '.$line.'.');
                $textByLine[$line] = $text;
            }
        }
        self::assertNotEmpty($textByLine);

        $fileLines = array_map(
            static fn (int $line): string => $textByLine[$line] ?? '// line '.$line.' is not in the fixtures',
            range(1, max(array_keys($textByLine))),
        );

        return implode("\n", $fileLines)."\n";
    }
}
