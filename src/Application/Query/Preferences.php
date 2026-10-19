<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Entity\Tour;

/**
 * me.schema.json#/$defs/Preferences: `preferences` of A1, and the response of A4.
 */
final readonly class Preferences
{
    /**
     * @param list<Tour> $completedTours
     */
    public function __construct(public array $completedTours)
    {
    }
}
