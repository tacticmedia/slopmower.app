<?php

declare(strict_types=1);

namespace App\Domain\Error;

/**
 * The details values are domain objects. REST and MCP normalize them to the shapes of error.schema.json.
 */
abstract class DomainError extends \RuntimeException
{
    abstract public function code(): ErrorCode;

    /**
     * @return array<string, mixed>|null
     */
    abstract public function details(): ?array;
}
