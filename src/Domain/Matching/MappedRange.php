<?php

declare(strict_types=1);

namespace App\Domain\Matching;

use App\Domain\Anchor\LineRange;

final readonly class MappedRange
{
    /**
     * @param int        $startLine Where the region starts at the target, also when no line of it is left
     * @param ?LineRange $range     Null when no line of the region is left
     * @param bool       $untouched No hunk changes, removes, or inserts a line inside the region
     */
    public function __construct(
        public int $startLine,
        public ?LineRange $range,
        public bool $untouched,
    ) {
    }
}
