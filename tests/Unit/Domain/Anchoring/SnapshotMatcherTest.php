<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Anchoring;

use App\Domain\Anchor\ContentSnapshot;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SnapshotCapture;
use App\Domain\Anchoring\ExactMatch;
use App\Domain\Anchoring\SnapshotMatcher;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SnapshotMatcherTest extends KernelTestCase
{
    private const array REGION = ['if ($order->isPaid()) {', '    $this->ship($order);', '}'];

    /** The region at lines 2 to 4 and 12 to 14. */
    private const array FILE_WITH_THE_REGION_TWICE = ['<?php', ...self::REGION, '', '', '', '', '', '', '', ...self::REGION];

    #[TestDox('Picks the exact match nearest to the mapped start')]
    public function testPicksTheNearestMatch(): void
    {
        $nearTheFirst = SnapshotMatcher::nearestMatch(self::FILE_WITH_THE_REGION_TWICE, self::regionSnapshot(), 6);
        $nearTheSecond = SnapshotMatcher::nearestMatch(self::FILE_WITH_THE_REGION_TWICE, self::regionSnapshot(), 8);

        self::assertEquals(ExactMatch::at(LineRange::of(2, 4)), $nearTheFirst);
        self::assertEquals(ExactMatch::at(LineRange::of(12, 14)), $nearTheSecond);
    }

    #[TestDox('F9: two matches at equal distance are ambiguous')]
    public function testEqualDistanceIsAmbiguous(): void
    {
        $match = SnapshotMatcher::nearestMatch(self::FILE_WITH_THE_REGION_TWICE, self::regionSnapshot(), 7);

        self::assertNotNull($match);
        self::assertTrue($match->isAmbiguous());
    }

    #[TestDox('F10: a trivial snapshot is never searched')]
    public function testTrivialSnapshotIsNeverSearched(): void
    {
        $closingBrace = SnapshotCapture::capture("}\n", LineRange::of(1, 1));
        $sevenCharacters = SnapshotCapture::capture("abc  d\n efg\n", LineRange::of(1, 2));
        $eightCharacters = SnapshotCapture::capture("abc  d\n efgh\n", LineRange::of(1, 2));

        self::assertSame([], SnapshotMatcher::exactMatches(['    }', '}'], $closingBrace));
        self::assertNull(SnapshotMatcher::nearestMatch(['    }', '}'], $closingBrace, 2));
        self::assertSame([], SnapshotMatcher::exactMatches(['abc d', 'efg'], $sevenCharacters));
        self::assertEquals([LineRange::of(1, 2)], SnapshotMatcher::exactMatches(['abc d', 'efgh'], $eightCharacters));
    }

    #[TestDox('F11: a whitespace or line-ending change still matches')]
    public function testIgnoresWhitespaceAndLineEndings(): void
    {
        $fileLines = ['<?php', '', "\tif (\$order->isPaid())  {\r", "\t\t\$this->ship(\$order); \r", "\t}\r"];

        self::assertEquals(ExactMatch::at(LineRange::of(3, 5)), SnapshotMatcher::nearestMatch($fileLines, self::regionSnapshot(), 1));
    }

    #[TestDox('A truncated snapshot matches a window with its normalizedHash')]
    public function testTruncatedSnapshotMatchesByHash(): void
    {
        $region = array_map(static fn (int $number): string => "\$total += \$line{$number};", range(1, 250));
        $sameKeptLinesOtherMiddle = array_replace($region, [124 => '$total = 0;']);
        $snapshot = SnapshotCapture::capture(implode("\n", $region)."\n", LineRange::of(1, 250));
        $fileLines = ['<?php', ...$sameKeptLinesOtherMiddle, '', ...$region];

        self::assertTrue($snapshot->truncated);
        self::assertEquals([LineRange::of(253, 502)], SnapshotMatcher::exactMatches($fileLines, $snapshot));
    }

    private static function regionSnapshot(): ContentSnapshot
    {
        return SnapshotCapture::capture(implode("\n", self::REGION)."\n", LineRange::of(1, 3));
    }
}
