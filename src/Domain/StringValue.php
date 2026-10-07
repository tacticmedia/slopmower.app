<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * For a value object that holds its string in `$value`.
 */
trait StringValue
{
    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
