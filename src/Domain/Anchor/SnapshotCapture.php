<?php

declare(strict_types=1);

namespace App\Domain\Anchor;

use App\Domain\Error\InvalidInput;

/**
 * Architecture section 5.1. Lines are split at LF only, as git counts them.
 */
final class SnapshotCapture
{
    private const string WHITESPACE = " \t\n\v\f\r";

    private const string WHITESPACE_RUN_PATTERN = '/['.self::WHITESPACE.']+/';

    private const int KEPT_LINES_PER_END = 100;

    public static function capture(string $content, LineRange $range): ContentSnapshot
    {
        $fileLines = explode("\n", $content);

        if ('' === $content || str_ends_with($content, "\n")) {
            array_pop($fileLines);
        }

        $fileLineCount = count($fileLines);

        if ($range->endLine > $fileLineCount) {
            throw InvalidInput::field('range.endLine', "Must not be after the last line of the file, line {$fileLineCount}.");
        }

        $start = $range->startLine - 1;
        $beforeStart = max(0, $start - ContentSnapshot::CONTEXT_LINES);
        $lineCount = $range->lineCount();
        $region = self::withoutTrailingWhitespace(array_slice($fileLines, $start, $lineCount));
        $truncated = $lineCount > ContentSnapshot::MAX_LINES;

        return ContentSnapshot::of(
            self::keptLines($region),
            $truncated,
            $lineCount,
            self::withoutTrailingWhitespace(array_slice($fileLines, $beforeStart, $start - $beforeStart)),
            self::withoutTrailingWhitespace(array_slice($fileLines, $range->endLine, ContentSnapshot::CONTEXT_LINES)),
            self::normalizedHash($region),
        );
    }

    public static function comparisonForm(string $line): string
    {
        return (string) preg_replace(self::WHITESPACE_RUN_PATTERN, ' ', trim($line, self::WHITESPACE));
    }

    /**
     * @param list<string> $region
     *
     * @return list<string> All lines up to 200, else the first 100 and the last 100
     */
    public static function keptLines(array $region): array
    {
        return count($region) > ContentSnapshot::MAX_LINES
            ? [...array_slice($region, 0, self::KEPT_LINES_PER_END), ...array_slice($region, -self::KEPT_LINES_PER_END)]
            : $region;
    }

    /**
     * @param list<string> $lines The full region, not a truncated snapshot
     */
    public static function normalizedHash(array $lines): string
    {
        return 'sha256:'.hash('sha256', implode("\n", array_map(self::comparisonForm(...), $lines)));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function withoutTrailingWhitespace(array $lines): array
    {
        return array_map(static fn (string $line): string => rtrim($line, self::WHITESPACE), $lines);
    }
}
