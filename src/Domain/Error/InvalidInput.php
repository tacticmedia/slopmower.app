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
            static fn (array $violation): string => '' === $violation['field'] ? $violation['message'] : "{$violation['field']}: {$violation['message']}",
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

    /**
     * The same violations under $prefix: `range.endLine` becomes `anchor.range.endLine`, and `` becomes `anchor`.
     */
    public function prefixed(string $prefix): self
    {
        return new self(array_map(
            static fn (array $violation): array => ['field' => '' === $violation['field'] ? $prefix : "{$prefix}.{$violation['field']}", 'message' => $violation['message']],
            $this->violations,
        ));
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
