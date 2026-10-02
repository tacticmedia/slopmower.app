<?php

declare(strict_types=1);

namespace App\Domain\Anchoring;

use App\Domain\Anchor\LineRange;

/**
 * Architecture section 5.4, `mapRange`.
 */
final class RangeMapper
{
    /**
     * @param list<Hunk> $hunks
     */
    public static function mapRange(LineRange $range, array $hunks): MappedRange
    {
        $start = self::mapLine(
            $range->startLine,
            $hunks,
            static fn (Hunk $hunk): int => 0 === $hunk->newCount ? $hunk->newStart + 1 : $hunk->newStart,
        );
        $end = self::mapLine(
            $range->endLine,
            $hunks,
            static fn (Hunk $hunk): int => $hunk->newStart + max($hunk->newCount, 1) - 1,
        );
        $touched = array_any(
            $hunks,
            static fn (Hunk $hunk): bool => !$hunk->endsBefore($range->startLine) && !$hunk->startsAfter($range->endLine),
        );

        return new MappedRange($start, $end >= $start ? LineRange::of($start, $end) : null, !$touched);
    }

    /**
     * @param list<Hunk>          $hunks
     * @param \Closure(Hunk): int $newSideLine The target line of a line inside the hunk
     */
    private static function mapLine(int $line, array $hunks, \Closure $newSideLine): int
    {
        $shift = 0;

        foreach ($hunks as $hunk) {
            if ($hunk->endsBefore($line)) {
                $shift += $hunk->delta();
            } elseif (!$hunk->startsAfter($line)) {
                return $newSideLine($hunk);
            }
        }

        return $line + $shift;
    }
}
