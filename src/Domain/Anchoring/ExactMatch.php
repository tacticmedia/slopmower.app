<?php

declare(strict_types=1);

namespace App\Domain\Anchoring;

use App\Domain\Anchor\LineRange;

final readonly class ExactMatch
{
    /**
     * @param ?LineRange $range Null when two matches are equally near
     */
    private function __construct(
        public ?LineRange $range,
    ) {
    }

    public static function at(LineRange $range): self
    {
        return new self($range);
    }

    public static function ambiguous(): self
    {
        return new self(null);
    }
}
