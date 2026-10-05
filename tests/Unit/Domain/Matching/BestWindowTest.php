<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Matching;

use App\Domain\Anchor\ContentSnapshot;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SnapshotCapture;
use App\Domain\Matching\BestWindow;
use App\Domain\Matching\FuzzyMatch;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BestWindowTest extends KernelTestCase
{
    private const array REGION = ['$total = 0;', 'foreach ($items as $item) {', '    $total += $item->price;', '}', 'return $total;'];

    #[TestDox('Finds the best window near the mapped start')]
    public function testFindsTheBestWindowNearTheMappedStart(): void
    {
        [$first, $second, $third, $fourth, $fifth] = self::REGION;
        $fourthLineEdited = [$first, $second, $third, '} // end', $fifth];
        $thirdLineEdited = [$first, $second, '    $total += $item->price * $item->quantity;', $fourth, $fifth];
        $filler = array_map(static fn (int $number): string => "// filler line {$number}", range(1, 13));
        $twoEditedCopies = ['<?php', ...$fourthLineEdited, ...$filler, ...$thirdLineEdited, '', ''];
        $farExactCopy = ['<?php', ...self::REGION, ...$filler, ...$thirdLineEdited, '', ''];

        self::assertEquals(new FuzzyMatch(LineRange::of(20, 24), 0.8), BestWindow::near($twoEditedCopies, self::regionSnapshot(), 21));
        self::assertEquals(new FuzzyMatch(LineRange::of(2, 6), 0.8), BestWindow::near($twoEditedCopies, self::regionSnapshot(), 11));
        self::assertEquals(new FuzzyMatch(LineRange::of(2, 6), 1.0), BestWindow::near($farExactCopy, self::regionSnapshot(), 21));
    }

    #[TestDox('A score below 0.6 gives no window')]
    public function testScoreBelowTheThresholdGivesNoWindow(): void
    {
        [$first, $second] = self::REGION;
        $twoLinesOfFiveLeft = ['<?php', $first, $second, '$sum = array_sum($prices);', 'echo $sum;', 'exit(0);'];

        self::assertNull(BestWindow::near($twoLinesOfFiveLeft, self::regionSnapshot(), 2));
    }

    #[TestDox('A file shorter than the snapshot is one window')]
    public function testShortFileIsOneWindow(): void
    {
        [$first, $second, , $fourth, $fifth] = self::REGION;

        self::assertEquals(
            new FuzzyMatch(LineRange::of(1, 4), 2 * 4 / 9),
            BestWindow::near([$first, $second, $fourth, $fifth], self::regionSnapshot(), 1),
        );
    }

    #[TestDox('Stops after 1000 similarity runs with the best window so far')]
    public function testStopsAfterTheSimilarityRunBudget(): void
    {
        [$first, $second, , $fourth, $fifth] = self::REGION;
        $thirdLineEdited = [$first, $second, '$total = -1;', $fourth, $fifth];
        $reversedBlocks = array_merge(...array_fill(0, 220, array_reverse(self::REGION)));
        $file = [...$thirdLineEdited, ...$reversedBlocks, ...self::REGION];

        self::assertEquals(new FuzzyMatch(LineRange::of(1, 5), 0.8), BestWindow::near($file, self::regionSnapshot(), 1));
        self::assertEquals(new FuzzyMatch(LineRange::of(1106, 1110), 1.0), BestWindow::near($file, self::regionSnapshot(), 1106));
    }

    #[TestDox('A trivial snapshot gives no window')]
    public function testTrivialSnapshotGivesNoWindow(): void
    {
        self::assertNull(BestWindow::near(['    }', '}'], SnapshotCapture::capture("}\n", LineRange::of(1, 1)), 1));
    }

    private static function regionSnapshot(): ContentSnapshot
    {
        return SnapshotCapture::capture(implode("\n", self::REGION)."\n", LineRange::of(1, 5));
    }
}
