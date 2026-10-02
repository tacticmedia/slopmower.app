<?php

declare(strict_types=1);

namespace App\Message;

use App\Domain\Identity\ReviewId;
use App\MessageHandler\RecomputeLocationsHandler;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Handled by {@see RecomputeLocationsHandler}.
 */
#[AsMessage('async')]
final readonly class RecomputeLocations
{
    public function __construct(public ReviewId $reviewId)
    {
    }
}
