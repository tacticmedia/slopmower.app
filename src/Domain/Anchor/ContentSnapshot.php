<?php

declare(strict_types=1);

namespace App\Domain\Anchor;

use App\Domain\Error\InvalidInput;
use App\Domain\Error\Violation;

/**
 * The region of an anchor at the review commit. {@see SnapshotCapture} builds it.
 */
final readonly class ContentSnapshot
{
    public const int MAX_LINES = 200;
    public const int MAX_CONTEXT_LINES = 3;
    private const string HASH_PATTERN = '/^sha256:[0-9a-f]{64}$/D';

    /**
     * @param list<string> $lines  the whole region, else its first and last MAX_LINES / 2 lines
     * @param list<string> $before
     * @param list<string> $after
     */
    private function __construct(
        public array $lines,
        public bool $truncated,
        public int $lineCount,
        public array $before,
        public array $after,
        public string $normalizedHash,
    ) {
    }

    /**
     * @param list<string> $lines
     * @param list<string> $before
     * @param list<string> $after
     */
    public static function fromParts(
        array $lines,
        bool $truncated,
        int $lineCount,
        array $before,
        array $after,
        string $normalizedHash,
        string $field = 'snapshot',
    ): self {
        $violations = [];
        if ($lineCount < 1) {
            $violations[] = new Violation($field.'.lineCount', 'must be 1 or more');
        }
        if ($truncated && ($lineCount <= self::MAX_LINES || self::MAX_LINES !== \count($lines))) {
            $violations[] = new Violation($field.'.lines', 'must hold 200 lines of a region of more than 200 lines when truncated');
        }
        if (!$truncated && (\count($lines) !== $lineCount || $lineCount > self::MAX_LINES)) {
            $violations[] = new Violation($field.'.lines', 'must hold all lineCount lines, at most 200, when not truncated');
        }
        foreach (['lines' => $lines, 'before' => $before, 'after' => $after] as $name => $values) {
            if (!self::areNormalized($values)) {
                $violations[] = new Violation($field.'.'.$name, 'must hold lines without LF or trailing whitespace');
            }
        }
        foreach (['before' => $before, 'after' => $after] as $name => $values) {
            if (\count($values) > self::MAX_CONTEXT_LINES) {
                $violations[] = new Violation($field.'.'.$name, 'must hold at most 3 lines');
            }
        }
        if (1 !== preg_match(self::HASH_PATTERN, $normalizedHash)) {
            $violations[] = new Violation($field.'.normalizedHash', 'must be sha256: and 64 lower-case hex characters');
        }
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }

        return new self($lines, $truncated, $lineCount, $before, $after, $normalizedHash);
    }

    public function equals(self $other): bool
    {
        return $this->lines === $other->lines
            && $this->truncated === $other->truncated
            && $this->lineCount === $other->lineCount
            && $this->before === $other->before
            && $this->after === $other->after
            && $this->normalizedHash === $other->normalizedHash;
    }

    /**
     * @param list<string> $lines
     */
    private static function areNormalized(array $lines): bool
    {
        foreach ($lines as $line) {
            if (str_contains($line, "\n") || SnapshotCapture::normalizeLine($line) !== $line) {
                return false;
            }
        }

        return true;
    }
}
