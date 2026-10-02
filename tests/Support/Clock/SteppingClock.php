<?php

declare(strict_types=1);

namespace App\Tests\Support\Clock;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\Clock\MockClock;

/**
 * A mock clock that moves forward by a fixed step after each read, so time passes while the code under test runs.
 * Install it with Clock::set() in a test that uses ClockSensitiveTrait, which restores the clock.
 */
final class SteppingClock implements ClockInterface
{
    private MockClock $clock;

    public function __construct(private readonly float $stepSeconds, \DateTimeImmutable|string $now = 'now')
    {
        $this->clock = new MockClock($now);
    }

    public function now(): DatePoint
    {
        $now = $this->clock->now();
        $this->clock->sleep($this->stepSeconds);

        return $now;
    }

    public function sleep(float|int $seconds): void
    {
        $this->clock->sleep($seconds);
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        $clone = clone $this;
        $clone->clock = $this->clock->withTimeZone($timezone);

        return $clone;
    }
}
