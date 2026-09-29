<?php

declare(strict_types=1);

namespace App\Domain\Error;

/**
 * The adapters map it to the error body of docs/specs. The message is English and not stable.
 */
abstract class DomainError extends \RuntimeException
{
    abstract public function code(): ErrorCode;

    /**
     * Domain values keyed like the `details` of the code in error.schema.json. The adapters normalize them.
     *
     * @return array<string, mixed>|null
     */
    public function details(): ?array
    {
        return null;
    }
}
