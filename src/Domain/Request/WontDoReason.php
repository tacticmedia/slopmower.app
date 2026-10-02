<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Error\InvalidInput;

/**
 * Keeps the text as written. Only the length check trims.
 */
final readonly class WontDoReason implements \Stringable
{
    private const int MIN_CHARACTERS = 20;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value, string $field = 'reason'): self
    {
        if (mb_strlen(trim($value), 'UTF-8') < self::MIN_CHARACTERS) {
            throw InvalidInput::field($field, 'Must have at least 20 characters after trimming.');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
