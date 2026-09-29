<?php

declare(strict_types=1);

namespace App\Domain\Git;

use App\Domain\Error\InvalidInput;

/**
 * Full SHA-1 or SHA-256 commit ID.
 */
final readonly class CommitSha implements \Stringable
{
    private const string PATTERN = '/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/D';

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value, string $field = 'commitSha'): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidInput::field($field, 'must be 40 or 64 lower-case hex characters');
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
}
