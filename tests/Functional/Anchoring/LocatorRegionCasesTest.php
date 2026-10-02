<?php

declare(strict_types=1);

namespace App\Tests\Functional\Anchoring;

use App\Tests\Support\Anchoring\LocatorFixtures;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Architecture section 5.5, the cases where the file stays and the region changes. The anchors are lines 10 to 12 of
 * 40 lines unless a case says otherwise.
 */
final class LocatorRegionCasesTest extends KernelTestCase
{
    use EnvironmentVariables;
    use LocalSources;
    use LocatorFixtures;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    private const string TOTALS = 'src/Billing/InvoiceTotals.php';

    private const string DUPLICATE = "    \$alpha = compute();\n    \$beta = \$alpha * 2;\n    return \$beta;\n";

    #[TestDox('F4: lines added above the region give shifted')]
    public function testLinesAddedAboveGiveShifted(): void
    {
        $base = $this->cloneSource([self::TOTALS => self::codeLines(1, 40)]);
        $target = $this->commitAndFetch([self::TOTALS => "<?php\n// Totals\n\n".self::codeLines(1, 40)]);

        self::assertSame(self::expected('shifted', self::TOTALS, [13, 15]), self::actual($this->locate($this->rangeAnchor($base, self::TOTALS, 10, 12), $target)));
    }

    #[TestDox('F5: an edited region gives changed with the mapped range and a similarity')]
    public function testEditedRegionGivesChanged(): void
    {
        $base = $this->cloneSource([self::TOTALS => self::codeLines(1, 40)]);
        $target = $this->commitAndFetch([self::TOTALS => self::codeLines(1, 10)."    \$total += 1100;\n".self::codeLines(12, 40)]);

        // 3 of 4 lines kept: 2 * 3 / (4 + 4).
        self::assertSame(self::expected('changed', self::TOTALS, [10, 13], 0.75), self::actual($this->locate($this->rangeAnchor($base, self::TOTALS, 10, 13), $target)));
    }

    #[TestDox('F6: a region moved inside the file gives shifted')]
    public function testRegionMovedInsideTheFileGivesShifted(): void
    {
        $base = $this->cloneSource([self::TOTALS => self::codeLines(1, 40)]);
        $target = $this->commitAndFetch([self::TOTALS => self::codeLines(1, 9).self::codeLines(13, 30).self::codeLines(10, 12).self::codeLines(31, 40)]);

        self::assertSame(self::expected('shifted', self::TOTALS, [28, 30]), self::actual($this->locate($this->rangeAnchor($base, self::TOTALS, 10, 12), $target)));
    }

    #[TestDox('F8: a deleted region gives orphaned region_deleted')]
    public function testDeletedRegionGivesOrphaned(): void
    {
        $base = $this->cloneSource([self::TOTALS => self::codeLines(1, 40)]);
        $target = $this->commitAndFetch([self::TOTALS => self::codeLines(1, 9).self::codeLines(13, 40)]);

        self::assertSame(self::expected('orphaned', null, null, 0.0, 'region_deleted'), self::actual($this->locate($this->rangeAnchor($base, self::TOTALS, 10, 12), $target)));
    }

    #[TestDox('F9: duplicate code at equal distance gives orphaned ambiguous')]
    public function testDuplicateCodeAtEqualDistanceGivesAmbiguous(): void
    {
        $base = $this->cloneSource([self::TOTALS => self::codeLines(1, 9).self::DUPLICATE.self::codeLines(13, 30)]);
        // The copies differ in indentation only, so git cannot pair them with the region, and the comparison form matches.
        $copy = str_replace('    ', "\t", self::DUPLICATE);
        $edited = "    \$gamma = 10;\n    \$gamma = 11;\n    \$gamma = 12;\n";
        $target = $this->commitAndFetch([self::TOTALS => self::codeLines(1, 4).$copy.self::codeLines(8, 9).$edited.self::codeLines(13, 14).$copy.self::codeLines(18, 30)]);

        self::assertSame(self::expected('orphaned', null, null, 0.0, 'ambiguous'), self::actual($this->locate($this->rangeAnchor($base, self::TOTALS, 10, 12), $target)));
    }

    #[TestDox('F10: a trivial snapshot uses diff mapping only')]
    public function testTrivialSnapshotUsesDiffMappingOnly(): void
    {
        $functionA = "function a()\n{\n    return 1;\n}\n\n";
        $base = $this->cloneSource([self::TOTALS => $functionA."function b()\n{\n    return 2;\n}\n"]);
        $target = $this->commitAndFetch([self::TOTALS => "// Totals\n// Billing\n".$functionA]);

        self::assertSame(self::expected('shifted', self::TOTALS, [6, 6]), self::actual($this->locate($this->rangeAnchor($base, self::TOTALS, 4, 4), $target)));
        self::assertSame(
            self::expected('orphaned', null, null, 0.0, 'region_deleted'),
            self::actual($this->locate($this->rangeAnchor($base, self::TOTALS, 9, 9), $target)),
            'The "}" of function a is not searched.',
        );
    }

    #[TestDox('F11: a whitespace or line-ending change gives exact or shifted')]
    public function testWhitespaceChangeGivesExact(): void
    {
        $base = $this->cloneSource([self::TOTALS => self::codeLines(1, 40)]);
        $target = $this->commitAndFetch([self::TOTALS => self::codeLines(1, 9)."\t\$total  +=  10;\r\n\t\$total += 11; \r\n\t\$total += 12;\r\n".self::codeLines(13, 40)]);

        self::assertSame(self::expected('exact', self::TOTALS, [10, 12]), self::actual($this->locate($this->rangeAnchor($base, self::TOTALS, 10, 12), $target)));
    }
}
