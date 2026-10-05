<?php

declare(strict_types=1);

namespace App\Domain\Git;

use App\Domain\Error\InvalidInput;

/**
 * The rules of `git check-ref-format --allow-onelevel`, plus valid UTF-8, at most 255 characters, and no leading "-". A SHA prefix is a valid ref name.
 */
final readonly class RefName implements \Stringable
{
    private const int MAX_CHARACTERS = 255;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value, string $field = 'ref'): self
    {
        $isValid = '' !== $value
            && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') <= self::MAX_CHARACTERS
            && !str_starts_with($value, '-')
            && '@' !== $value
            && !str_ends_with($value, '.')
            && 1 !== preg_match('/\.\.|@\{|[\x00-\x20\x7f~^:?*\[\\\\]/', $value)
            && !array_any(
                explode('/', $value),
                static fn (string $component): bool => '' === $component || str_starts_with($component, '.') || str_ends_with($component, '.lock'),
            );

        if (!$isValid) {
            throw InvalidInput::field($field, 'Must be a git ref name of at most 255 characters without a leading "-".');
        }

        return new self($value);
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
