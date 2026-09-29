<?php

declare(strict_types=1);

namespace App\Domain\Anchor;

use App\Domain\Error\InvalidInput;

/**
 * Capture rules and comparison form of architecture section 5.1. Lines split at LF, as git counts them,
 * so a final LF does not start another line.
 */
final class SnapshotCapture
{
    private const string WHITESPACE = " \t\n\v\f\r";
    // Explicit ASCII class: `\s` depends on the locale.
    private const string WHITESPACE_RUN_PATTERN = '/[\x09-\x0D\x20]+/';

    public static function capture(string $fileContent, LineRange $range, string $field = 'range'): ContentSnapshot
    {
        $fileLines = self::fileLines($fileContent);
        if ($range->endLine > \count($fileLines)) {
            throw InvalidInput::field($field.'.endLine', \sprintf('must not exceed the %d lines of the file', \count($fileLines)));
        }

        $startOffset = $range->startLine - 1;
        $region = \array_slice($fileLines, $startOffset, $range->lineCount());
        $truncated = \count($region) > ContentSnapshot::MAX_LINES;
        $edgeLineCount = intdiv(ContentSnapshot::MAX_LINES, 2);
        $contextStartOffset = max(0, $startOffset - ContentSnapshot::MAX_CONTEXT_LINES);

        return ContentSnapshot::fromParts(
            $truncated ? [...\array_slice($region, 0, $edgeLineCount), ...\array_slice($region, -$edgeLineCount)] : $region,
            $truncated,
            \count($region),
            \array_slice($fileLines, $contextStartOffset, $startOffset - $contextStartOffset),
            \array_slice($fileLines, $range->endLine, ContentSnapshot::MAX_CONTEXT_LINES),
            self::normalizedHash($region),
        );
    }

    /**
     * @return list<string> normalized, line N at index N - 1
     */
    public static function fileLines(string $fileContent): array
    {
        $lines = explode("\n", $fileContent);
        if ('' === $lines[\count($lines) - 1]) {
            array_pop($lines);
        }

        return array_map(self::normalizeLine(...), $lines);
    }

    /**
     * Also replaces each invalid UTF-8 sequence and each NUL with U+FFFD, as a browser shows them,
     * so that every snapshot fits JSON and jsonb.
     */
    public static function normalizeLine(string $line): string
    {
        return rtrim(str_replace("\0", "\u{FFFD}", \UConverter::transcode($line, 'UTF-8', 'UTF-8')), self::WHITESPACE);
    }

    public static function comparisonForm(string $line): string
    {
        return preg_replace(self::WHITESPACE_RUN_PATTERN, ' ', trim($line, self::WHITESPACE))
            ?? throw new \LogicException(preg_last_error_msg());
    }

    /**
     * @param list<string> $lines the full region
     */
    public static function normalizedHash(array $lines): string
    {
        return 'sha256:'.hash('sha256', implode("\n", array_map(self::comparisonForm(...), $lines)));
    }
}
