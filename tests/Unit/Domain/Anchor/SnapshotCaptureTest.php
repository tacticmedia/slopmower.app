<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Anchor;

use App\Domain\Anchor\ContentSnapshot;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SnapshotCapture;
use App\Tests\Support\Domain\InvalidInputAssertions;
use App\Tests\Support\Specs\SpecsFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SnapshotCaptureTest extends KernelTestCase
{
    use InvalidInputAssertions;
    use SpecsFiles;

    #[TestDox('Normalizes CRLF and trailing whitespace')]
    public function testNormalizesLineEndingsAndTrailingWhitespace(): void
    {
        $snapshot = SnapshotCapture::capture("first\r\n  second \t\r\nthird \r\n", LineRange::of(1, 3));

        self::assertSame(['first', '  second', 'third'], $snapshot->lines);
    }

    #[TestDox('Keeps 200 lines and cuts 201 lines to the first and last 100 with lineCount 201')]
    public function testTruncatesAbove200Lines(): void
    {
        $fileLines = array_map(static fn (int $number): string => "line {$number}", range(1, 205));
        $content = implode("\n", $fileLines)."\n";

        $kept = SnapshotCapture::capture($content, LineRange::of(1, 200));
        $cut = SnapshotCapture::capture($content, LineRange::of(2, 202));

        self::assertFalse($kept->truncated);
        self::assertSame(array_slice($fileLines, 0, 200), $kept->lines);
        self::assertTrue($cut->truncated);
        self::assertSame(201, $cut->lineCount);
        self::assertSame([...array_slice($fileLines, 1, 100), ...array_slice($fileLines, 102, 100)], $cut->lines);
        self::assertSame('sha256:'.hash('sha256', implode("\n", array_slice($fileLines, 1, 201))), $cut->normalizedHash);
    }

    #[TestDox('Takes fewer context lines at the file edges')]
    public function testTakesFewerContextLinesAtTheEdges(): void
    {
        $content = "l1\nl2\nl3\nl4\nl5\nl6";

        $nearStart = SnapshotCapture::capture($content, LineRange::of(2, 5));
        $nearEnd = SnapshotCapture::capture($content, LineRange::of(4, 5));

        self::assertSame(['l1'], $nearStart->before);
        self::assertSame(['l6'], $nearStart->after);
        self::assertSame(['l1', 'l2', 'l3'], $nearEnd->before);
        self::assertSame(['l6'], $nearEnd->after);
    }

    #[TestDox('Produces the normalizedHash of the snapshots in docs/specs/fixtures/request.opened-range.json and request.in-progress.json')]
    #[DataProvider('fixtures')]
    public function testMatchesTheSpecsFixtures(string $fixture): void
    {
        $expected = self::specsJson("fixtures/{$fixture}")['anchor']['snapshot'];
        $content = implode("\n", [...$expected['before'], ...$expected['lines'], ...$expected['after']])."\n";
        $range = LineRange::of(count($expected['before']) + 1, count($expected['before']) + count($expected['lines']));

        $snapshot = SnapshotCapture::capture($content, $range);

        self::assertSame($expected['normalizedHash'], $snapshot->normalizedHash);
        self::assertTrue($snapshot->equals(ContentSnapshot::of(
            $expected['lines'],
            $expected['truncated'],
            $expected['lineCount'],
            $expected['before'],
            $expected['after'],
            $expected['normalizedHash'],
        )));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        yield 'range anchor' => ['request.opened-range.json'];
        yield 'symbol anchor' => ['request.in-progress.json'];
    }

    #[TestDox('Gives the same hash for a re-indented region')]
    public function testIgnoresIndentationInTheHash(): void
    {
        $spaces = SnapshotCapture::capture("    if (\$a) {\n        return  \$b;\n    }\n", LineRange::of(1, 3));
        $tabs = SnapshotCapture::capture("\tif (\$a)\t{\n\t\treturn \$b;\t\n\t}\n", LineRange::of(1, 3));

        self::assertSame($spaces->normalizedHash, $tabs->normalizedHash);
        self::assertNotSame($spaces->lines, $tabs->lines);
    }

    #[TestDox('Rejects a range that ends after the last line of the file')]
    public function testRejectsARangeAfterTheLastLine(): void
    {
        self::assertInvalidInput(['range.endLine'], static fn () => SnapshotCapture::capture("a\nb\n", LineRange::of(2, 3)));
    }
}
