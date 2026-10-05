<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Anchoring;

use App\Domain\Anchor\LineRange;
use App\Domain\Anchoring\Hunk;
use App\Domain\Anchoring\MappedRange;
use App\Domain\Anchoring\RangeMapper;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RangeMapperTest extends KernelTestCase
{
    #[TestDox('F4: lines added or removed above the region shift the range')]
    public function testShiftsForChangesAbove(): void
    {
        $twoAddedOneRemovedOneChangedBelow = [new Hunk(3, 0, 4, 2), new Hunk(6, 1, 7, 0), new Hunk(20, 1, 21, 1)];
        $threeRemoved = [new Hunk(2, 3, 1, 0)];

        self::assertEquals(
            new MappedRange(11, LineRange::of(11, 13), true),
            RangeMapper::mapRange(LineRange::of(10, 12), $twoAddedOneRemovedOneChangedBelow),
        );
        self::assertEquals(
            new MappedRange(7, LineRange::of(7, 9), true),
            RangeMapper::mapRange(LineRange::of(10, 12), $threeRemoved),
        );
    }

    #[TestDox('F5: an edited region maps to the new side with some lines kept')]
    public function testMapsAnEditedRegionToTheNewSide(): void
    {
        $middleLineSplit = [new Hunk(12, 1, 12, 2)];
        $startLineInsideAGrownHunk = [new Hunk(9, 2, 9, 3)];
        $singleLineRewritten = [new Hunk(7, 1, 7, 1)];

        self::assertEquals(new MappedRange(10, LineRange::of(10, 15), false), RangeMapper::mapRange(LineRange::of(10, 14), $middleLineSplit));
        self::assertEquals(new MappedRange(9, LineRange::of(9, 15), false), RangeMapper::mapRange(LineRange::of(10, 14), $startLineInsideAGrownHunk));
        self::assertEquals(new MappedRange(7, LineRange::of(7, 7), false), RangeMapper::mapRange(LineRange::of(7, 7), $singleLineRewritten));
    }

    #[TestDox('A deleted region keeps no lines and starts at the line after the deletion')]
    public function testDeletedRegionKeepsNoLines(): void
    {
        self::assertEquals(new MappedRange(13, null, false), RangeMapper::mapRange(LineRange::of(10, 12), [new Hunk(1, 0, 1, 4), new Hunk(9, 5, 12, 0)]));
    }

    #[TestDox('An insertion between two lines of the region touches it, an insertion after its last line does not')]
    public function testInsertionBoundaries(): void
    {
        $range = LineRange::of(10, 12);

        self::assertEquals(new MappedRange(10, LineRange::of(10, 14), false), RangeMapper::mapRange($range, [new Hunk(11, 0, 12, 2)]));
        self::assertEquals(new MappedRange(10, LineRange::of(10, 12), true), RangeMapper::mapRange($range, [new Hunk(12, 0, 13, 2)]));
        self::assertEquals(new MappedRange(12, LineRange::of(12, 14), true), RangeMapper::mapRange($range, [new Hunk(9, 0, 10, 2)]));
    }
}
