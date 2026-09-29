<?php

declare(strict_types=1);

namespace App\Domain\Anchor;

use App\Domain\Error\InvalidInput;
use App\Domain\Error\Violation;

/**
 * 1-based, inclusive.
 */
final readonly class LineRange
{
    private function __construct(
        public int $startLine,
        public int $endLine,
    ) {
    }

    public static function of(int $startLine, int $endLine, string $field = 'range'): self
    {
        $violations = [];
        if ($startLine < 1) {
            $violations[] = new Violation($field.'.startLine', 'must be 1 or more');
        }
        if ($endLine < max(1, $startLine)) {
            $violations[] = new Violation($field.'.endLine', 'must be 1 or more and not before startLine');
        }
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }

        return new self($startLine, $endLine);
    }

    public static function singleLine(int $line, string $field = 'range'): self
    {
        return self::of($line, $line, $field);
    }

    public function lineCount(): int
    {
        return $this->endLine - $this->startLine + 1;
    }

    public function isSingleLine(): bool
    {
        return $this->startLine === $this->endLine;
    }

    public function equals(self $other): bool
    {
        return $this->startLine === $other->startLine && $this->endLine === $other->endLine;
    }
}
