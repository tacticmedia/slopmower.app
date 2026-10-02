<?php

declare(strict_types=1);

namespace App\Domain\Anchor;

use App\Domain\Error\InvalidInput;

final readonly class LineRange
{
    private function __construct(
        public int $startLine,
        public int $endLine,
    ) {
    }

    public static function of(int $startLine, int $endLine): self
    {
        $violations = [];

        if ($startLine < 1) {
            $violations[] = ['field' => 'range.startLine', 'message' => 'Must be 1 or more.'];
        }

        if ($endLine < $startLine) {
            $violations[] = ['field' => 'range.endLine', 'message' => 'Must not be before startLine.'];
        }

        if ([] !== $violations) {
            throw new InvalidInput($violations);
        }

        return new self($startLine, $endLine);
    }

    public function lineCount(): int
    {
        return $this->endLine - $this->startLine + 1;
    }

    public function equals(self $other): bool
    {
        return $this->startLine === $other->startLine && $this->endLine === $other->endLine;
    }
}
