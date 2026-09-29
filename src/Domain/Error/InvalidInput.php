<?php

declare(strict_types=1);

namespace App\Domain\Error;

final class InvalidInput extends DomainError
{
    /**
     * @param non-empty-list<Violation> $violations
     */
    private function __construct(private readonly array $violations)
    {
        parent::__construct('Invalid input: '.implode('; ', array_map(
            static fn (Violation $violation): string => ('' === $violation->field ? 'input' : $violation->field).' '.$violation->message,
            $violations,
        )));
    }

    public static function field(string $field, string $message): self
    {
        return new self([new Violation($field, $message)]);
    }

    /**
     * @param non-empty-list<Violation> $violations
     */
    public static function fromViolations(array $violations): self
    {
        return new self($violations);
    }

    /**
     * @return non-empty-list<Violation>
     */
    public function violations(): array
    {
        return $this->violations;
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
        return ['violations' => array_map(
            static fn (Violation $violation): array => ['field' => $violation->field, 'message' => $violation->message],
            $this->violations,
        )];
    }
}
