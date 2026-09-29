<?php

declare(strict_types=1);

namespace App\Domain\Git;

use App\Domain\Error\InvalidInput;

/**
 * A branch, tag, or commit name as a caller gives it: the rules of `git check-ref-format --allow-onelevel`,
 * plus no leading dash, so that it can never be read as an option.
 */
final readonly class RefName implements \Stringable
{
    private const int MAX_LENGTH = 255;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value, string $field = 'ref'): self
    {
        $problem = self::problem($value);
        if (null !== $problem) {
            throw InvalidInput::field($field, $problem);
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

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    private static function problem(string $value): ?string
    {
        return match (true) {
            '' === $value, \strlen($value) > self::MAX_LENGTH => 'must have 1 to 255 characters',
            str_starts_with($value, '-') => 'must not start with -',
            '@' === $value => 'must not be @',
            1 === preg_match('/[\x00-\x20\x7f~^:?*\[\\\\]/', $value) => 'must not contain control characters, spaces, or any of ~ ^ : ? * [ \\',
            str_contains($value, '..') => 'must not contain ..',
            str_contains($value, '@{') => 'must not contain @{',
            str_starts_with($value, '/'), str_ends_with($value, '/'), str_contains($value, '//') => 'must not have an empty / segment',
            str_ends_with($value, '.') => 'must not end with .',
            1 === preg_match('#(^|/)\.#', $value) => 'must not have a segment that starts with .',
            1 === preg_match('#\.lock(/|$)#D', $value) => 'must not have a segment that ends with .lock',
            default => null,
        };
    }
}
