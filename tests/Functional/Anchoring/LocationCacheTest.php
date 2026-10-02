<?php

declare(strict_types=1);

namespace App\Tests\Functional\Anchoring;

use App\Application\ReviewCommands;
use App\Domain\Actor\Actor;
use App\Domain\Git\RefName;
use App\Domain\Identity\ReviewId;
use App\Domain\Review\Command\StartReview;
use App\Tests\Support\Anchoring\LocatorFixtures;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LocationCacheTest extends KernelTestCase
{
    use EnvironmentVariables;
    use LocalSources;
    use LocatorFixtures;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    private const string TOTALS = 'src/Billing/InvoiceTotals.php';

    #[TestDox('A second locate of the same event and target reads the cache')]
    public function testSecondLocateReadsTheCache(): void
    {
        $base = $this->cloneSource([self::TOTALS => self::codeLines(1, 40)]);
        $target = $this->commitAndFetch([self::TOTALS => self::codeLines(1, 10)."    \$total += 1100;\n".self::codeLines(12, 40)]);
        $anchor = $this->rangeAnchor($base, self::TOTALS, 10, 13);
        // The cache stores entries only for an event of an existing review.
        $openedEventId = self::getContainer()->get(ReviewCommands::class)
            ->recordStarted(ReviewId::generate(), $this->repository->getId(), new StartReview('Cache', RefName::fromString('main'), $base), Actor::user($this->user->getId()))[0]->id;
        $first = $this->locate($anchor, $target, $openedEventId);
        new Filesystem()->remove(self::clonePath($this->repository));

        self::assertEquals($first, $this->locate($anchor, $target, $openedEventId), 'Without the clone, only the cache can answer.');
        self::assertSame(self::expected('changed', self::TOTALS, [10, 13], 0.75), self::actual($first));
    }
}
