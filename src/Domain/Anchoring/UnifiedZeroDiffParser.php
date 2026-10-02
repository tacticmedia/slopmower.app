<?php

declare(strict_types=1);

namespace App\Domain\Anchoring;

final class UnifiedZeroDiffParser
{
    private const string HUNK_HEADER_PATTERN = '/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/';

    /**
     * @param string $diff The `git diff --unified=0` output of one file
     *
     * @return list<Hunk>
     *
     * @throws \UnexpectedValueException When the diff holds context lines, more than one file, a binary file, or a
     *                                   hunk with other line counts than its header
     */
    public static function parse(string $diff): array
    {
        $hunks = [];
        $open = null;
        $removed = $added = 0;
        $seenFileHeader = false;

        foreach (explode("\n", rtrim($diff, "\n")) as $index => $line) {
            if (1 === preg_match(self::HUNK_HEADER_PATTERN, $line, $numbers, \PREG_UNMATCHED_AS_NULL)) {
                if (null !== $open) {
                    $hunks[] = self::checked($open, $removed, $added);
                }

                $open = new Hunk((int) $numbers[1], (int) ($numbers[2] ?? 1), (int) $numbers[3], (int) ($numbers[4] ?? 1));
                $removed = $added = 0;
            } elseif (str_starts_with($line, 'diff ')) {
                if ($seenFileHeader || null !== $open) {
                    throw new \UnexpectedValueException('The diff holds more than one file.');
                }

                $seenFileHeader = true;
            } elseif (str_starts_with($line, 'Binary files ')) {
                throw new \UnexpectedValueException('The diff is of a binary file.');
            } elseif (null === $open) {
                continue;
            } elseif (str_starts_with($line, '-')) {
                ++$removed;
            } elseif (str_starts_with($line, '+')) {
                ++$added;
            } elseif (!str_starts_with($line, '\\')) {
                $lineNumber = $index + 1;

                throw new \UnexpectedValueException("Unexpected line {$lineNumber} in a --unified=0 diff.");
            }
        }

        if (null !== $open) {
            $hunks[] = self::checked($open, $removed, $added);
        }

        return $hunks;
    }

    private static function checked(Hunk $hunk, int $removed, int $added): Hunk
    {
        if ($hunk->oldCount !== $removed || $hunk->newCount !== $added) {
            throw new \UnexpectedValueException("The hunk at old line {$hunk->oldStart} has {$removed} removed and {$added} added lines, other than its header.");
        }

        return $hunk;
    }
}
