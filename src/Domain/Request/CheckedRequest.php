<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Actor\ActorKind;
use App\Domain\Identity\ReviewId;
use App\Domain\Location\Location;

/**
 * A request that links the pull request of a {@see CheckRunOutput}.
 */
final readonly class CheckedRequest
{
    /**
     * @param Location|null $location At the head SHA. Null for a general request, a finished request, and a location
     *                                that the server could not compute.
     */
    public function __construct(
        public ReviewId $reviewId,
        public string $reviewTitle,
        public string $reviewUrl,
        public bool $reviewClosed,
        public string $url,
        public string $body,
        public RequestStatus $status,
        public ?ActorKind $wontDoBy,
        public bool $general,
        public ?Location $location,
    ) {
    }

    public function at(?Location $location): self
    {
        return clone ($this, ['location' => $location]);
    }

    /**
     * Nobody can change a request of a closed review.
     */
    public function finished(): bool
    {
        return $this->reviewClosed || $this->status->isClosed($this->wontDoBy);
    }

    /**
     * @return 'warning'|'notice'|null `notice` while the request waits for the user, null when it is finished
     */
    public function annotationLevel(): ?string
    {
        return match (true) {
            $this->finished() => null,
            RequestStatus::ReadyForReview === $this->status, RequestStatus::WontDo === $this->status => 'notice',
            default => 'warning',
        };
    }
}
