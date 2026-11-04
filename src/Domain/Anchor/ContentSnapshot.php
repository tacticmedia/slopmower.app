<?php

declare(strict_types=1);

namespace App\Domain\Anchor;

use App\Domain\Error\InvalidInput;

/**
 * Architecture section 5.1. Build it from file content with {@see SnapshotCapture::capture()}. D113: a stored snapshot
 * has no code text, {@see withoutText()}, and a reader rebuilds it from the blob.
 */
final readonly class ContentSnapshot
{
    public const int MAX_LINES = 200;
    public const int CONTEXT_LINES = 3;

    /**
     * @param list<string>|null $lines
     * @param list<string>|null $before
     * @param list<string>|null $after
     */
    private function __construct(
        public ?array $lines,
        public bool $truncated,
        public int $lineCount,
        public ?array $before,
        public ?array $after,
        public string $normalizedHash,
    ) {
    }

    /**
     * @param list<string>|null $lines  Null together with $before and $after
     * @param list<string>|null $before
     * @param list<string>|null $after
     */
    public static function of(?array $lines, bool $truncated, int $lineCount, ?array $before, ?array $after, string $normalizedHash): self
    {
        $violations = [];

        if ($lineCount < 1) {
            $violations[] = ['field' => 'snapshot.lineCount', 'message' => 'Must be 1 or more.'];
        }

        if ($truncated !== ($lineCount > self::MAX_LINES)) {
            $violations[] = ['field' => 'snapshot.truncated', 'message' => 'Must be true exactly when lineCount is above 200.'];
        }

        if ((null === $lines) !== (null === $before) || (null === $lines) !== (null === $after)) {
            $violations[] = ['field' => 'snapshot.lines', 'message' => 'Must be null exactly when before and after are null.'];
        }

        if (null !== $lines && count($lines) !== min($lineCount, self::MAX_LINES)) {
            $violations[] = ['field' => 'snapshot.lines', 'message' => 'Must hold all lines up to 200, else the first 100 and the last 100.'];
        }

        foreach (['before' => $before ?? [], 'after' => $after ?? []] as $field => $contextLines) {
            if (count($contextLines) > self::CONTEXT_LINES) {
                $violations[] = ['field' => "snapshot.{$field}", 'message' => 'Must hold at most 3 lines.'];
            }
        }

        if (1 !== preg_match('/^sha256:[0-9a-f]{64}$/D', $normalizedHash)) {
            $violations[] = ['field' => 'snapshot.normalizedHash', 'message' => 'Must be "sha256:" and 64 lower-case hex characters.'];
        }

        if ([] !== $violations) {
            throw new InvalidInput($violations);
        }

        return new self($lines, $truncated, $lineCount, $before, $after, $normalizedHash);
    }

    public function withoutText(): self
    {
        return new self(null, $this->truncated, $this->lineCount, null, null, $this->normalizedHash);
    }

    /**
     * @return list<string>
     *
     * @throws \LogicException Without the code text, which the caller rebuilds first
     */
    public function textLines(): array
    {
        return $this->lines ?? throw new \LogicException('The snapshot has no code text.');
    }
}
