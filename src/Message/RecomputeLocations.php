<?php

declare(strict_types=1);

namespace App\Message;

use App\Domain\Identity\ReviewId;
use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('async')]
final readonly class RecomputeLocations
{
    public function __construct(public ReviewId $reviewId)
    {
    }
}
