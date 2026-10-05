<?php

declare(strict_types=1);

namespace App\Domain\Git;

use App\Domain\Error\InvalidInput;

final readonly class CommitShaPrefix implements \Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value, string $field = 'commit'): self
    {
        if (1 !== preg_match('/^[0-9a-f]{7,64}$/D', $value)) {
            throw InvalidInput::field($field, 'Must be a commit SHA or its prefix: 7 to 64 lower-case hex characters.');
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
