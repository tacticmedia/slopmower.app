<?php

declare(strict_types=1);

/*
 * The rate limiter policies read microtime() and the cache adapters, which keep the limiter state, read time()
 * directly. These functions shadow them in their namespaces with the global clock, so ClockSensitiveTrait::mockTime()
 * drives the limiters and the expiry of their state. tests/bootstrap.php loads this file before any limiter or adapter
 * runs, because PHP caches the global function for a call site after its first call.
 */

namespace Symfony\Component\RateLimiter\Policy;

use Symfony\Component\Clock\Clock;

function microtime(bool $asFloat = false): float|string
{
    $now = Clock::get()->now();

    return $asFloat ? (float) $now->format('U.u') : $now->format('0.u00 U');
}

namespace Symfony\Component\Cache\Adapter;

use Symfony\Component\Clock\Clock;

function time(): int
{
    return Clock::get()->now()->getTimestamp();
}
