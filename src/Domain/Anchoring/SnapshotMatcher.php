<?php

declare(strict_types=1);

namespace App\Domain\Anchoring;

use App\Domain\Anchor\ContentSnapshot;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SnapshotCapture;

/**
 * Architecture section 5.4, `exactNormalizedMatches` and `nearest`. A snapshot that is not distinctive is never searched.
 */
final class SnapshotMatcher
{
    public const int MIN_DISTINCTIVE_CHARACTERS = 8;

    public static function isDistinctive(ContentSnapshot $snapshot): bool
    {
        $characters = implode('', array_map(
            static fn (string $line): string => str_replace(' ', '', SnapshotCapture::comparisonForm($line)),
            $snapshot->lines,
        ));

        return mb_strlen($characters, 'UTF-8') >= self::MIN_DISTINCTIVE_CHARACTERS;
    }

    /**
     * @param list<string> $fileLines
     *
     * @return list<LineRange> In file order
     */
    public static function exactMatches(array $fileLines, ContentSnapshot $snapshot): array
    {
        if (!self::isDistinctive($snapshot)) {
            return [];
        }

        $fileForms = array_map(SnapshotCapture::comparisonForm(...), $fileLines);
        $snapshotForms = array_map(SnapshotCapture::comparisonForm(...), $snapshot->lines);
        $matches = [];

        for ($offset = 0, $lastOffset = count($fileForms) - $snapshot->lineCount; $offset <= $lastOffset; ++$offset) {
            if ($fileForms[$offset] !== $snapshotForms[0]) {
                continue;
            }

            $window = array_slice($fileForms, $offset, $snapshot->lineCount);

            if (
                SnapshotCapture::keptLines($window) === $snapshotForms
                && (!$snapshot->truncated || SnapshotCapture::normalizedHash($window) === $snapshot->normalizedHash)
            ) {
                $matches[] = LineRange::of($offset + 1, $offset + $snapshot->lineCount);
            }
        }

        return $matches;
    }

    /**
     * @param list<string> $fileLines
     *
     * @return ?ExactMatch Null when the snapshot is not distinctive or has no match
     */
    public static function nearestMatch(array $fileLines, ContentSnapshot $snapshot, int $nearLine): ?ExactMatch
    {
        $distance = static fn (LineRange $match): int => abs($match->startLine - $nearLine);
        $matches = self::exactMatches($fileLines, $snapshot);
        usort($matches, static fn (LineRange $a, LineRange $b): int => $distance($a) <=> $distance($b));

        return match (true) {
            [] === $matches => null,
            isset($matches[1]) && $distance($matches[0]) === $distance($matches[1]) => ExactMatch::ambiguous(),
            default => ExactMatch::at($matches[0]),
        };
    }
}
