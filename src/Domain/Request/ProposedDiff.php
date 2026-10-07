<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Error\InvalidInput;
use App\Domain\StringValue;

/**
 * A unified diff in git format, as `git diff` writes it. Each file starts with a `diff --git ` line.
 */
final readonly class ProposedDiff implements \Stringable
{
    use StringValue;

    private const int MAX_CHARACTERS = 65536;
    private const int MAX_FILES = 9;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value, string $field = 'diff'): self
    {
        if ('' === $value || mb_strlen($value, 'UTF-8') > self::MAX_CHARACTERS) {
            throw InvalidInput::field($field, 'Must have 1 to 65,536 characters.');
        }

        $files = preg_match_all('/^diff --git /m', $value);

        if ($files < 1 || $files > self::MAX_FILES) {
            throw InvalidInput::field($field, 'Must be a git diff of 1 to 9 files, each with a "diff --git" line.');
        }

        return new self($value);
    }
}
