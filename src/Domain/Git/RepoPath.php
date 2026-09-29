<?php

declare(strict_types=1);

namespace App\Domain\Git;

use App\Domain\Error\InvalidInput;

/**
 * Path relative to the repository root, `RepoPath` of common.schema.json.
 */
final readonly class RepoPath implements \Stringable
{
    private const int MAX_LENGTH = 4096;

    // The schema pattern, with `s` so that a line break cannot hide a `//` or a dot segment.
    private const string PATTERN = '#^(?!/)(?!-)(?!.*//)(?!(.*/)?\.\.?(/|$))[^\x00]*[^/\x00]$#Dsu';

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value, string $field = 'path'): self
    {
        if (mb_strlen($value, 'UTF-8') > self::MAX_LENGTH || 1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidInput::field($field, 'must be a relative path with / separators, no leading / or -, no empty, . or .. segment, and no NUL byte');
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
