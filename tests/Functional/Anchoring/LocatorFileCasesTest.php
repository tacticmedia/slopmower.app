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
 * Architecture section 5.5, the cases where the file of the anchor changes.
 */
final class LocatorFileCasesTest extends KernelTestCase
{
    use EnvironmentVariables;
    use LocalSources;
    use LocatorFixtures;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    private const string TOTALS = 'src/Billing/InvoiceTotals.php';

    private const string RENAMED = 'src/Billing/Totals.php';

    private const string TAX = 'src/Billing/TaxRounding.php';

    #[TestDox('F1: a deleted file without a rename gives orphaned file_deleted')]
    public function testDeletedFileIsOrphaned(): void
    {
        $base = $this->cloneSource([self::TOTALS => self::codeLines(1, 30), 'README.md' => "Billing\n"]);
        $target = $this->commitAndFetch([self::TOTALS => null]);

        $fileDeleted = self::expected('orphaned', null, null, 0.0, 'file_deleted');
        self::assertSame($fileDeleted, self::actual($this->locate($this->rangeAnchor($base, self::TOTALS, 10, 12), $target)));
        self::assertSame($fileDeleted, self::actual($this->locate($this->fileAnchor($base, self::TOTALS), $target)));
    }

    #[TestDox('F2: a renamed file with 50% or more similarity maps the path')]
    public function testRenamedFileMapsThePath(): void
    {
        $base = $this->cloneSource([self::TOTALS => self::codeLines(1, 30)]);
        $target = $this->commitAndFetch([self::RENAMED => "<?php\n\n".self::codeLines(1, 30)], [self::TOTALS => self::RENAMED]);

        self::assertSame(self::expected('shifted', self::RENAMED, [12, 14]), self::actual($this->locate($this->rangeAnchor($base, self::TOTALS, 10, 12), $target)));
    }

    #[TestDox('A file renamed into a directory of its own name, next to new files, maps the path')]
    public function testFileRenamedIntoDirectoryOfItsName(): void
    {
        $rules = 'src/Billing/Rules';
        $base = $this->cloneSource([$rules => self::codeLines(1, 30)]);
        $target = $this->commitAndFetch([$rules => null, "{$rules}/Rules.php" => "<?php\n\n".self::codeLines(1, 30), "{$rules}/Extra.php" => self::codeLines(1, 30, 'tax')]);

        self::assertSame(self::expected('shifted', "{$rules}/Rules.php", [12, 14]), self::actual($this->locate($this->rangeAnchor($base, $rules, 10, 12), $target)));
    }

    #[TestDox('A region with a line above 128 KiB is searched by the start of that line')]
    public function testRegionWithLongLineIsSearchedByItsStart(): void
    {
        $region = self::codeLines(10, 11).'    $long = \''.str_repeat('x', 140_000)."';\n";
        $base = $this->cloneSource([self::TOTALS => self::codeLines(1, 9).$region.self::codeLines(13, 30), self::TAX => self::codeLines(1, 10, 'tax')]);
        $target = $this->commitAndFetch([self::TOTALS => self::codeLines(1, 9).self::codeLines(13, 30), self::TAX => self::codeLines(1, 2, 'tax').$region.self::codeLines(3, 10, 'tax')]);

        self::assertSame(self::expected('shifted', self::TAX, [3, 5]), self::actual($this->locate($this->rangeAnchor($base, self::TOTALS, 10, 12), $target)));
    }

    #[TestDox('F3: a renamed and much changed file is found by searchOtherFiles or gives orphaned')]
    public function testRenamedAndMuchChangedFile(): void
    {
        $base = $this->cloneSource([self::TOTALS => self::codeLines(1, 30)]);
        $rewritten = self::codeLines(1, 4, 'shipping').self::codeLines(10, 12).self::codeLines(5, 40, 'shipping');
        $target = $this->commitAndFetch([self::RENAMED => $rewritten], [self::TOTALS => self::RENAMED]);

        self::assertSame(self::expected('shifted', self::RENAMED, [5, 7]), self::actual($this->locate($this->rangeAnchor($base, self::TOTALS, 10, 12), $target)));
        self::assertSame(self::expected('orphaned', null, null, 0.0, 'file_deleted'), self::actual($this->locate($this->rangeAnchor($base, self::TOTALS, 20, 22), $target)));
    }

    #[TestDox('F7: a region moved to another file gives shifted through git grep')]
    public function testRegionMovedToAnotherFile(): void
    {
        $base = $this->cloneSource([self::TOTALS => self::codeLines(1, 30), self::TAX => self::codeLines(1, 10, 'tax')]);
        $target = $this->commitAndFetch([
            self::TOTALS => self::codeLines(1, 9).self::codeLines(15, 30),
            self::TAX => self::codeLines(1, 2, 'tax').self::codeLines(10, 14).self::codeLines(3, 10, 'tax'),
        ]);

        self::assertSame(self::expected('shifted', self::TAX, [3, 7]), self::actual($this->locate($this->rangeAnchor($base, self::TOTALS, 10, 14), $target)));
    }
}
