<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Location\Location;
use App\Domain\Location\LocationTarget;

/**
 * location.schema.json. One cached location serves every target that has its SHA.
 */
final readonly class LocationView
{
    public function __construct(
        public LocationTarget $target,
        public Location $location,
    ) {
    }
}
