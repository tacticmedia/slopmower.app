<?php

declare(strict_types=1);

namespace App\Domain\Review;

use App\Domain\Actor\Actor;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\Violation;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\ReviewId;

/**
 * The review data that the deciders need.
 */
final readonly class ReviewSnapshot
{
    private function __construct(
        public ReviewId $reviewId,
        public CommitSha $commitSha,
        public ?\DateTimeImmutable $closedAt,
        public ?Actor $closedBy,
        public ?string $closeNote,
    ) {
    }

    public static function open(ReviewId $reviewId, CommitSha $commitSha): self
    {
        return new self($reviewId, $commitSha, null, null, null);
    }

    public static function closed(ReviewId $reviewId, CommitSha $commitSha, \DateTimeImmutable $closedAt, Actor $closedBy, ?string $closeNote = null): self
    {
        return new self($reviewId, $commitSha, $closedAt, $closedBy, $closeNote);
    }

    /**
     * Rebuilds a stored review and checks that closedBy and closeNote go with closedAt.
     */
    public static function fromParts(
        ReviewId $reviewId,
        CommitSha $commitSha,
        ?\DateTimeImmutable $closedAt = null,
        ?Actor $closedBy = null,
        ?string $closeNote = null,
    ): self {
        $violations = [];
        if ((null === $closedAt) !== (null === $closedBy)) {
            $violations[] = new Violation('closedBy', null === $closedAt ? 'must be null while the review is open' : 'is required when closedAt is set');
        }
        if (null === $closedAt && null !== $closeNote) {
            $violations[] = new Violation('closeNote', 'must be null while the review is open');
        }
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }

        return new self($reviewId, $commitSha, $closedAt, $closedBy, $closeNote);
    }

    /**
     * @throws \LogicException when the review is closed
     */
    public function close(\DateTimeImmutable $closedAt, Actor $closedBy, ?string $closeNote): self
    {
        if ($this->isClosed()) {
            throw new \LogicException(\sprintf('Review %s is already closed.', $this->reviewId));
        }

        return new self($this->reviewId, $this->commitSha, $closedAt, $closedBy, $closeNote);
    }

    /**
     * @phpstan-assert-if-true !null $this->closedAt
     */
    public function isClosed(): bool
    {
        return null !== $this->closedAt;
    }

    public function state(): ReviewState
    {
        return $this->isClosed() ? ReviewState::Closed : ReviewState::Open;
    }
}
