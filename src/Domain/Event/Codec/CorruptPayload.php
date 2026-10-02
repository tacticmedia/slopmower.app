<?php

declare(strict_types=1);

namespace App\Domain\Event\Codec;

/**
 * Stored JSON that no codec can read. Not a DomainError, so REST and MCP answer internal_error.
 */
final class CorruptPayload extends \UnexpectedValueException
{
    /**
     * @param non-empty-list<array{field: string, message: string}> $violations Fields are dotted paths, '' for the whole payload
     */
    public function __construct(public readonly array $violations, ?\Throwable $previous = null)
    {
        parent::__construct('Corrupt payload. '.implode(' ', array_map(
            static fn (array $violation): string => "{$violation['field']}: {$violation['message']}",
            $violations,
        )), 0, $previous);
    }

    public static function field(string $field, string $message): self
    {
        return new self([['field' => $field, 'message' => $message]]);
    }
}
