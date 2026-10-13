<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Deletes the expired rows of `rate_limiter.cache`, which the pool deletes otherwise only when their key is read again.
 */
final readonly class PruneRateLimiterCacheJob
{
}
