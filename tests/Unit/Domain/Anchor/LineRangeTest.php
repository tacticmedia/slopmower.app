<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Anchor;

use App\Domain\Anchor\LineRange;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LineRangeTest extends KernelTestCase
{
    use InvalidInputAssertions;

    #[TestDox('Rejects a start line below 1 and an end line before the start line')]
    public function testRejectsInvalidRanges(): void
    {
        self::assertInvalidInput(['range.startLine'], static fn () => LineRange::of(0, 5));
        self::assertInvalidInput(['range.endLine'], static fn () => LineRange::of(5, 4));
        self::assertInvalidInput(['range.startLine', 'range.endLine'], static fn () => LineRange::of(0, -1));
    }

    #[TestDox('Accepts a single line and counts the lines of a range')]
    public function testCountsLines(): void
    {
        self::assertSame(1, LineRange::of(3, 3)->lineCount());
        self::assertSame(7, LineRange::of(52, 58)->lineCount());
    }
}
