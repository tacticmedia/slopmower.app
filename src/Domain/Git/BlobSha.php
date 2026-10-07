<?php

declare(strict_types=1);

namespace App\Domain\Git;

use App\Domain\Error\InvalidInput;
use App\Domain\StringValue;

final readonly class BlobSha implements \Stringable
{
    use StringValue;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value, string $field = 'blobSha'): self
    {
        if (1 !== preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/D', $value)) {
            throw InvalidInput::field($field, 'Must be a full object ID: 40 or 64 lower-case hex characters.');
        }

        return new self($value);
    }
}
