<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Anchor\LineRange;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Location\Location;
use App\Domain\Location\LocationStatus;
use App\Domain\Location\LocationTarget;
use App\Domain\Location\OrphanReason;

/**
 * location.schema.json. One cached location serves every target that has its SHA.
 */
final readonly class LocationView
{
    private function __construct(
        public LocationTarget $target,
        public CommitSha $targetSha,
        public LocationStatus $status,
        public ?RepoPath $path,
        public ?LineRange $range,
        public float $confidence,
        public ?OrphanReason $reason,
    ) {
    }

    public static function of(LocationTarget $target, Location $location): self
    {
        return new self($target, $location->targetSha, $location->status, $location->path, $location->range, $location->confidence, $location->reason);
    }
}
