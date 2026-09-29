<?php

declare(strict_types=1);

namespace App\Domain\Error;

use App\Domain\Identity\ReviewId;

final class ReviewClosed extends DomainError
{
    public function __construct(
        private readonly ReviewId $reviewId,
        private readonly \DateTimeImmutable $closedAt,
    ) {
        parent::__construct(\sprintf('Review %s is closed.', $reviewId));
    }

    public function code(): ErrorCode
    {
        return ErrorCode::ReviewClosed;
    }

    /**
     * @return array{reviewId: ReviewId, closedAt: \DateTimeImmutable}
     */
    public function details(): array
    {
        return ['reviewId' => $this->reviewId, 'closedAt' => $this->closedAt];
    }
}
