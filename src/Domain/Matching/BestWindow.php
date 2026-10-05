<?php

declare(strict_types=1);

namespace App\Domain\Matching;

use App\Domain\Anchor\ContentSnapshot;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SnapshotCapture;

/**
 * Architecture section 5.4, `bestWindow`. A window has the snapshot's lineCount lines, or all lines of a shorter file.
 * The highest {@see LineSimilarity} wins, then the window nearest to the mapped start, then the upper window.
 * A truncated snapshot compares the kept lines of each window. A snapshot that is not distinctive is never searched.
 * After MAX_SIMILARITY_RUNS similarity runs, the search stops with the best window so far.
 */
final class BestWindow
{
    public const float MIN_SCORE = 0.6;
    public const int MAX_SIMILARITY_RUNS = 1000;

    /**
     * @param list<string> $fileLines
     *
     * @return ?FuzzyMatch Null when no window scores MIN_SCORE or more
     */
    public static function near(array $fileLines, ContentSnapshot $snapshot, int $nearLine): ?FuzzyMatch
    {
        $windowLineCount = min($snapshot->lineCount, count($fileLines));

        if (0 === $windowLineCount || !SnapshotMatcher::isDistinctive($snapshot)) {
            return null;
        }

        $fileForms = array_map(SnapshotCapture::comparisonForm(...), $fileLines);
        $snapshotForms = array_map(SnapshotCapture::comparisonForm(...), $snapshot->lines);
        $snapshotFormCounts = array_count_values($snapshotForms);
        $best = null;
        $similarityRuns = 0;

        foreach (self::startLinesByDistance(count($fileForms) - $windowLineCount + 1, $nearLine) as $startLine) {
            $window = SnapshotCapture::keptLines(array_slice($fileForms, $startLine - 1, $windowLineCount));
            $lineCount = count($window) + count($snapshotForms);
            $scoreLimit = 2.0 * self::sharedLineCount($window, $snapshotFormCounts) / $lineCount;

            if ($scoreLimit < self::MIN_SCORE || $scoreLimit <= ($best->score ?? 0.0)) {
                continue;
            }

            if (self::MAX_SIMILARITY_RUNS === $similarityRuns++) {
                break;
            }

            $score = LineSimilarity::between($window, $snapshotForms);

            if ($score >= self::MIN_SCORE && $score > ($best->score ?? 0.0)) {
                $best = new FuzzyMatch(LineRange::of($startLine, $startLine + $windowLineCount - 1), $score);
            }
        }

        return $best;
    }

    /**
     * @return list<int>
     */
    private static function startLinesByDistance(int $lastStartLine, int $nearLine): array
    {
        $startLines = range(1, $lastStartLine);
        usort($startLines, static fn (int $a, int $b): int => [abs($a - $nearLine), $a] <=> [abs($b - $nearLine), $b]);

        return $startLines;
    }

    /**
     * The size of the multiset intersection, an upper limit of the longest common subsequence.
     *
     * @param list<string>          $window
     * @param array<array-key, int> $snapshotFormCounts
     */
    private static function sharedLineCount(array $window, array $snapshotFormCounts): int
    {
        $shared = 0;

        foreach ($window as $form) {
            if (($snapshotFormCounts[$form] ?? 0) > 0) {
                --$snapshotFormCounts[$form];
                ++$shared;
            }
        }

        return $shared;
    }
}
