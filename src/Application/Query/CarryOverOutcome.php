<?php

declare(strict_types=1);

namespace App\Application\Query;

/**
 * carry-over-result.schema.json#/$defs/CarryOverOutcome.
 */
enum CarryOverOutcome: string
{
    case Carried = 'carried';
    case AlreadyCarried = 'already_carried';
    case NotCarried = 'not_carried';
}
