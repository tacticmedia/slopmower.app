<?php

declare(strict_types=1);

namespace App\Message;

use App\Domain\Identity\ReviewId;
use App\MessageHandler\IndexReviewHandler;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Handled by {@see IndexReviewHandler}.
 */
#[AsMessage('async')]
final readonly class IndexReview
{
    public function __construct(public ReviewId $reviewId)
    {
    }
}
