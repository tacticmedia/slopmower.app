<?php

declare(strict_types=1);

namespace App\Domain\Git;

use App\Domain\Error\InvalidInput;
use App\Domain\StringValue;

final readonly class RepoPath implements \Stringable
{
    use StringValue;

    private const int MAX_CHARACTERS = 4096;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value, string $field = 'path'): self
    {
        $isValid = '' !== $value
            && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') <= self::MAX_CHARACTERS
            && !str_contains($value, "\0")
            && !str_starts_with($value, '-')
            && !array_any(explode('/', $value), static fn (string $segment): bool => in_array($segment, ['', '.', '..'], true));

        if (!$isValid) {
            throw InvalidInput::field($field, 'Must be a relative path of at most 4096 characters with "/" separators: no leading "/" or "-", no empty, "." or ".." segment, no NUL byte.');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
