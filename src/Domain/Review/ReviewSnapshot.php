<?php

declare(strict_types=1);

namespace App\Domain\Review;

use App\Domain\Actor\Actor;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Identity\ReviewId;

/**
 * One review as {@see ReviewReducer} folds it from the review events.
 */
final readonly class ReviewSnapshot
{
    public function __construct(
        public ReviewId $id,
        public string $title,
        public RefName $ref,
        public CommitSha $commitSha,
        public Actor $startedBy,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $closedAt,
        public ?Actor $closedBy,
        public ?string $closeNote,
    ) {
        if ((null === $closedAt) !== (null === $closedBy)) {
            throw new \LogicException('closedAt and closedBy are set together.');
        }
    }

    public function isClosed(): bool
    {
        return null !== $this->closedAt;
    }
}
