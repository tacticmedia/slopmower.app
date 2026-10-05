<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Matching;

use App\Domain\Matching\Hunk;
use App\Domain\Matching\UnifiedZeroDiffParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UnifiedZeroDiffParserTest extends KernelTestCase
{
    private const string FILE_HEADER = <<<'DIFF'
        diff --git a/f.txt b/f.txt
        index 71ac1b5..7588c95 100644
        --- a/f.txt
        +++ b/f.txt

        DIFF;

    #[TestDox('Parses added, deleted, and changed lines')]
    #[DataProvider('singleHunks')]
    public function testParsesEachKindOfHunk(string $hunkLines, Hunk $expected): void
    {
        self::assertEquals([$expected], UnifiedZeroDiffParser::parse(self::FILE_HEADER.$hunkLines));
    }

    /**
     * @return iterable<string, array{string, Hunk}>
     */
    public static function singleHunks(): iterable
    {
        yield 'added' => ["@@ -1,0 +2,2 @@ a\n+X\n+Y\n", new Hunk(1, 0, 2, 2)];
        yield 'deleted' => ["@@ -6 +5,0 @@ e\n-f\n", new Hunk(6, 1, 5, 0)];
        yield 'changed' => ["@@ -4,2 +4 @@ c\n--- d\n-+++ e\n+de\n", new Hunk(4, 2, 4, 1)];
    }

    #[TestDox('Parses several hunks')]
    public function testParsesSeveralHunks(): void
    {
        $diff = self::FILE_HEADER.<<<'DIFF'
            @@ -1,0 +2,2 @@ a
            +X
            +Y
            @@ -4 +6 @@ c
            -d
            +d2
            @@ -6 +7,0 @@ e
            -f
            @@ -8,0 +10 @@ h
            +Z

            DIFF;

        self::assertEquals(
            [new Hunk(1, 0, 2, 2), new Hunk(4, 1, 6, 1), new Hunk(6, 1, 7, 0), new Hunk(8, 0, 10, 1)],
            UnifiedZeroDiffParser::parse($diff),
        );
    }

    #[TestDox('Handles a file without a newline at the end')]
    public function testIgnoresTheNoNewlineMarker(): void
    {
        $diff = self::FILE_HEADER.<<<'DIFF'
            @@ -3 +3,2 @@ b
            -last
            \ No newline at end of file
            +last
            +added
            \ No newline at end of file

            DIFF;

        self::assertEquals([new Hunk(3, 1, 3, 2)], UnifiedZeroDiffParser::parse($diff));
    }

    #[TestDox('Rejects a context line, a second file, a binary file, and line counts that contradict the hunk header')]
    #[DataProvider('malformedDiffs')]
    public function testRejectsMalformedDiffs(string $diff): void
    {
        $this->expectException(\UnexpectedValueException::class);

        UnifiedZeroDiffParser::parse($diff);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedDiffs(): iterable
    {
        yield 'context line' => [self::FILE_HEADER."@@ -4 +4 @@\n-d\n+d2\n e\n"];
        yield 'second file' => [self::FILE_HEADER."@@ -4 +4 @@\n-d\n+d2\n".self::FILE_HEADER];
        yield 'binary file' => ["diff --git a/f.png b/f.png\nindex 71ac1b5..7588c95 100644\nBinary files a/f.png and b/f.png differ\n"];
        yield 'missing added line' => [self::FILE_HEADER."@@ -4 +4,2 @@\n-d\n+d2\n"];
    }
}
