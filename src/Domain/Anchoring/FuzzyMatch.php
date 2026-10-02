<?php

declare(strict_types=1);

namespace App\Domain\Anchoring;

use App\Domain\Anchor\LineRange;

final readonly class FuzzyMatch
{
    public function __construct(
        public LineRange $range,
        public float $score,
    ) {
    }
}
