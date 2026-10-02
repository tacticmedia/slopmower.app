<?php

declare(strict_types=1);

namespace App\Tests\Support\Clock;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\Clock\MockClock;

/**
 * A mock clock that runs a callback after each sleep, so a test can act while the code under test polls.
 * Install it with Clock::set() in a test that uses ClockSensitiveTrait, which restores the clock.
 */
final class SleepHookClock implements ClockInterface
{
    private MockClock $clock;

    private int $sleeps = 0;

    /**
     * @param \Closure(int): void $afterSleep Gets the number of sleeps so far
     */
    public function __construct(private readonly \Closure $afterSleep)
    {
        $this->clock = new MockClock();
    }

    public function now(): DatePoint
    {
        return $this->clock->now();
    }

    public function sleep(float|int $seconds): void
    {
        $this->clock->sleep($seconds);
        ($this->afterSleep)(++$this->sleeps);
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        $clone = clone $this;
        $clone->clock = $this->clock->withTimeZone($timezone);

        return $clone;
    }

    public function sleeps(): int
    {
        return $this->sleeps;
    }
}
