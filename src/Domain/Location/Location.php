<?php

declare(strict_types=1);

namespace App\Domain\Location;

use App\Domain\Anchor\LineRange;
use App\Domain\Error\InvalidInput;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;

/**
 * Where an anchor is at another commit. The rules of location.schema.json. The range is null for a file anchor.
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
        return new self($targetSha, LocationStatus::Exact, $path, $range, 1.0, null);
    }

    public static function shifted(CommitSha $targetSha, RepoPath $path, ?LineRange $range): self
    {
        return new self($targetSha, LocationStatus::Shifted, $path, $range, 1.0, null);
    }

    public static function changed(CommitSha $targetSha, RepoPath $path, ?LineRange $range, float $confidence): self
    {
        // Written so that NAN fails too.
        if (!($confidence >= 0.0 && $confidence <= 1.0)) {
            throw InvalidInput::field('confidence', 'Must be between 0 and 1.');
        }

        return new self($targetSha, LocationStatus::Changed, $path, $range, $confidence, null);
    }

    public static function orphaned(CommitSha $targetSha, OrphanReason $reason): self
    {
        return new self($targetSha, LocationStatus::Orphaned, null, null, 0.0, $reason);
    }
}
