<?php

declare(strict_types=1);

namespace App\Domain\Anchoring;

use App\Domain\Anchor\SnapshotCapture;

/**
 * Architecture section 5.4, `similarity`: `2 * m / (|a| + |b|)`, with m the longest common subsequence of the lines
 * in comparison form. Two empty lists are identical.
 */
final class LineSimilarity
{
    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    public static function between(array $a, array $b): float
    {
        $lineCount = count($a) + count($b);

        if (0 === $lineCount) {
            return 1.0;
        }

        $common = self::longestCommonSubsequence(
            array_map(SnapshotCapture::comparisonForm(...), $a),
            array_map(SnapshotCapture::comparisonForm(...), $b),
        );

        return 2.0 * $common / $lineCount;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function longestCommonSubsequence(array $a, array $b): int
    {
        $previous = array_fill(0, count($b) + 1, 0);

        foreach ($a as $lineOfA) {
            $current = [0];

            foreach ($b as $j => $lineOfB) {
                $current[] = $lineOfA === $lineOfB ? $previous[$j] + 1 : max($previous[$j + 1], $current[$j]);
            }

            $previous = $current;
        }

        return $previous[count($b)];
    }
}
