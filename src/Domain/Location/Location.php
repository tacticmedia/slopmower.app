<?php

declare(strict_types=1);

namespace App\Domain\Location;

use App\Domain\Anchor\LineRange;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\Violation;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;

/**
 * Where an anchor is at another commit. Computed and cached, never an event.
 * `range` is null for a file anchor and for an orphaned location.
 */
final readonly class Location
{
    private function __construct(
        public CommitSha $targetSha,
        public LocationStatus $status,
        public ?RepoPath $path,
        public ?LineRange $range,
        public float $confidence,
        public ?OrphanReason $reason,
    ) {
    }

    public static function exact(CommitSha $targetSha, RepoPath $path, ?LineRange $range): self
    {
        return self::fromParts($targetSha, LocationStatus::Exact, $path, $range, 1.0, null);
    }

    public static function shifted(CommitSha $targetSha, RepoPath $path, ?LineRange $range): self
    {
        return self::fromParts($targetSha, LocationStatus::Shifted, $path, $range, 1.0, null);
    }

    public static function changed(CommitSha $targetSha, RepoPath $path, ?LineRange $range, float $confidence): self
    {
        return self::fromParts($targetSha, LocationStatus::Changed, $path, $range, $confidence, null);
    }

    public static function orphaned(CommitSha $targetSha, OrphanReason $reason): self
    {
        return self::fromParts($targetSha, LocationStatus::Orphaned, null, null, 0.0, $reason);
    }

    /**
     * Rebuilds a cached location and checks the rules of its status.
     */
    public static function fromParts(
        CommitSha $targetSha,
        LocationStatus $status,
        ?RepoPath $path,
        ?LineRange $range,
        float $confidence,
        ?OrphanReason $reason,
        string $field = 'location',
    ): self {
        $orphaned = LocationStatus::Orphaned === $status;
        $violations = [];
        if ($orphaned !== (null === $path)) {
            $violations[] = new Violation($field.'.path', $orphaned ? 'must be null when the status is orphaned' : 'is required unless the status is orphaned');
        }
        if ($orphaned && null !== $range) {
            $violations[] = new Violation($field.'.range', 'must be null when the status is orphaned');
        }
        if ($orphaned !== (null !== $reason)) {
            $violations[] = new Violation($field.'.reason', $orphaned ? 'is required when the status is orphaned' : 'must be null unless the status is orphaned');
        }
        $confidenceProblem = self::confidenceProblem($status, $confidence);
        if (null !== $confidenceProblem) {
            $violations[] = new Violation($field.'.confidence', $confidenceProblem);
        }
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }

        return new self($targetSha, $status, $path, $range, $confidence, $reason);
    }

    public function equals(self $other): bool
    {
        return $this->targetSha->equals($other->targetSha)
            && $this->status === $other->status
            && (null === $this->path ? null === $other->path : null !== $other->path && $this->path->equals($other->path))
            && (null === $this->range ? null === $other->range : null !== $other->range && $this->range->equals($other->range))
            && $this->confidence === $other->confidence
            && $this->reason === $other->reason;
    }

    private static function confidenceProblem(LocationStatus $status, float $confidence): ?string
    {
        return match ($status) {
            LocationStatus::Exact, LocationStatus::Shifted => 1.0 === $confidence ? null : 'must be 1 when the status is exact or shifted',
            LocationStatus::Orphaned => 0.0 === $confidence ? null : 'must be 0 when the status is orphaned',
            LocationStatus::Changed => $confidence >= 0.0 && $confidence <= 1.0 ? null : 'must be from 0 to 1',
        };
    }
}
