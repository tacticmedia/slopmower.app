<?php

declare(strict_types=1);

/*
 * The rate limiter policies read microtime() directly. This function shadows it in their namespace with the global
 * clock, so ClockSensitiveTrait::mockTime() drives them. tests/bootstrap.php loads it before any limiter runs, because
 * PHP caches the global function for a call site after its first call.
 */

namespace Symfony\Component\RateLimiter\Policy;

use Symfony\Component\Clock\Clock;

function microtime(bool $asFloat = false): float|string
{
    $now = Clock::get()->now();

    return $asFloat ? (float) $now->format('U.u') : $now->format('0.u00 U');
}
