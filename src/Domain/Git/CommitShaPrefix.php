<?php

declare(strict_types=1);

namespace App\Domain\Git;

use App\Domain\Error\InvalidInput;

/**
 * Full or abbreviated commit ID: the input form of `at` and of a review `ref`.
 */
final readonly class CommitShaPrefix implements \Stringable
{
    private const string PATTERN = '/^[0-9a-f]{7,64}$/D';

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value, string $field = 'commit'): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidInput::field($field, 'must be 7 to 64 lower-case hex characters');
        }

        return new self($value);
    }

    public function matches(CommitSha $sha): bool
    {
        return str_starts_with($sha->toString(), $this->value);
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
}
