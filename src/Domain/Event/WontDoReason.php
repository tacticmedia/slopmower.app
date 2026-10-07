<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Error\InvalidInput;
use App\Domain\StringValue;

/**
 * Keeps the text as written. Only the length check trims.
 */
final readonly class WontDoReason implements \Stringable
{
    use StringValue;

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
}
