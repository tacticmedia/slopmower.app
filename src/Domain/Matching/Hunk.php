<?php

declare(strict_types=1);

namespace App\Domain\Matching;

/**
 * A `git diff --unified=0` hunk. A side with a count of 0 starts at the line before the change.
 */
final readonly class Hunk
{
    public function __construct(
        public int $oldStart,
        public int $oldCount,
        public int $newStart,
        public int $newCount,
    ) {
    }

    public function delta(): int
    {
        return $this->newCount - $this->oldCount;
    }

    /**
     * True for an insertion directly above the line.
     */
    public function endsBefore(int $oldLine): bool
    {
        return $this->oldStart + max($this->oldCount, 1) - 1 < $oldLine;
    }

    /**
     * True for an insertion directly below the line.
     */
    public function startsAfter(int $oldLine): bool
    {
        return 0 === $this->oldCount ? $this->oldStart >= $oldLine : $this->oldStart > $oldLine;
    }
}
