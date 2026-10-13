<?php

declare(strict_types=1);

namespace App\Domain\Error;

final class RateLimited extends DomainError
{
    /**
     * @param int $limit The calls that the refusing limiter allows in its interval
     */
    public function __construct(
        public readonly int $retryAfterSeconds,
        public readonly int $limit,
        public readonly int $remaining,
    ) {
        parent::__construct("Too many calls. Wait {$retryAfterSeconds} seconds, then try again.");
    }

    public function code(): ErrorCode
    {
        return ErrorCode::RateLimited;
    }

    /**
     * @return array{retryAfterSeconds: int, limit: int}
     */
    public function details(): array
    {
        return ['retryAfterSeconds' => $this->retryAfterSeconds, 'limit' => $this->limit];
    }
}
