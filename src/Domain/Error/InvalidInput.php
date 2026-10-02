<?php

declare(strict_types=1);

namespace App\Domain\Error;

final class InvalidInput extends DomainError
{
    /**
     * @param non-empty-list<array{field: string, message: string}> $violations
     */
    public function __construct(private readonly array $violations)
    {
        parent::__construct('Invalid input. '.implode(' ', array_map(
            static fn (array $violation): string => "{$violation['field']}: {$violation['message']}",
            $violations,
        )));
    }

    /**
     * @param string $field Dotted input path, '' for the whole input
     */
    public static function field(string $field, string $message): self
    {
        return new self([['field' => $field, 'message' => $message]]);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::InvalidInput;
    }

    /**
     * @return array{violations: non-empty-list<array{field: string, message: string}>}
     */
    public function details(): array
    {
        return ['violations' => $this->violations];
    }
}
