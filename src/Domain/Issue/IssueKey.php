<?php

declare(strict_types=1);

namespace App\Domain\Issue;

use App\Domain\Error\InvalidInput;

/**
 * `<project key>-<number>`, for example `SM-12` (D116).
 */
final readonly class IssueKey implements \Stringable
{
    public const string PROJECT_KEY_PATTERN = '/^[A-Z]{2,10}$/D';
    private const string PATTERN = '/^([A-Z]{2,10})-([1-9][0-9]{0,8})$/D';

    public function __construct(
        public string $projectKey,
        public int $number,
    ) {
    }

    public static function fromString(string $value, string $field): self
    {
        return 1 === preg_match(self::PATTERN, $value, $match)
            ? new self($match[1], (int) $match[2])
            : throw InvalidInput::field($field, 'Must be an issue key, for example SM-12.');
    }

    public function toString(): string
    {
        return "{$this->projectKey}-{$this->number}";
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
