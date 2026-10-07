<?php

declare(strict_types=1);

namespace App\Tests\Functional\Index;

use App\Application\Query\IndexStatus;
use App\Application\ReviewCommands;
use App\Domain\Git\RefName;
use App\Domain\Identity\ReviewId;
use App\Domain\Review\IndexState;
use App\Entity\Repository;
use App\Repository\ReviewRepository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use App\Tests\Support\Source\LocalSources;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IndexMemoryTest extends KernelTestCase
{
    use EnvironmentVariables;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewDataFixtures;
    use TemporaryDirectories;

    private const int FIRST_FILES = 100;

    private const int MEASURED_FILES = 1500;

    /** The class, its constant, its two properties, and its three methods. */
    private const int SYMBOLS_PER_FILE = 7;

    /** Not zero: in debug mode, the Doctrine profiler and the log handlers keep the statements of the job. */
    private const int PEAK_GROWTH_LIMIT_BYTES = 64 * 1024 * 1024;

    #[TestDox('Indexing 1,500 new PHP files after the first files grows the peak memory by less than 64 MB')]
    public function testPeakMemoryGrowthIsBounded(): void
    {
        $this->useTemporaryLocalSources();
        $source = $this->newSource();
        $source->commit(self::phpFiles(1, self::FIRST_FILES));
        self::enterNewTenant();
        $repository = self::connect();
        self::runQueuedMessages();
        self::runIndexJob($repository);
        $source->commit(self::phpFiles(self::FIRST_FILES + 1, self::FIRST_FILES + self::MEASURED_FILES));

        gc_collect_cycles();
        memory_reset_peak_usage();
        $baselineBytes = memory_get_usage();
        $reviewId = self::runIndexJob($repository);
        $peakGrowthBytes = memory_get_peak_usage() - $baselineBytes;

        $phpFiles = self::FIRST_FILES + self::MEASURED_FILES;
        $review = self::getContainer()->get(ReviewRepository::class)->findFresh($reviewId) ?? self::fail('No review.');
        $status = IndexStatus::of($review);
        self::assertSame([IndexState::Ready, $phpFiles + 1, $phpFiles * self::SYMBOLS_PER_FILE], [$status->state, $status->filesDone, $status->symbolsTotal], 'The PHP files and README.md.');
        $connection = self::getContainer()->get(Connection::class);
        // The job writes about 12 batches, so a batch that loses or duplicates rows changes the counts.
        self::assertSame([$phpFiles, $phpFiles * self::SYMBOLS_PER_FILE], [
            (int) $connection->fetchOne('SELECT COUNT(*) FROM indexed_blob'),
            (int) $connection->fetchOne('SELECT COUNT(*) FROM symbol'),
        ]);
        self::assertLessThan(self::PEAK_GROWTH_LIMIT_BYTES, $peakGrowthBytes);
    }

    private static function runIndexJob(Repository $repository): ReviewId
    {
        $reviewId = self::getContainer()->get(ReviewCommands::class)->start($repository->getId(), RefName::fromString('main'), null, RequestScenario::user());
        self::runQueuedMessages();

        return $reviewId;
    }

    /**
     * @return array<string, string> Classes with {@see SYMBOLS_PER_FILE} symbols each, by path
     */
    private static function phpFiles(int $first, int $last): array
    {
        $files = [];

        foreach (range($first, $last) as $number) {
            $module = intdiv($number, 100);
            $files["src/Module{$module}/Class{$number}.php"] = <<<PHP
                <?php

                namespace App\\Module{$module};

                /**
                 * Class {$number}.
                 */
                final class Class{$number}
                {
                    public const int NUMBER = {$number};

                    private int \$count = 0;

                    public function __construct(private readonly string \$name)
                    {
                    }

                    public function name(): string
                    {
                        return \$this->name;
                    }

                    public function increment(): int
                    {
                        return ++\$this->count;
                    }
                }

                PHP;
        }

        return $files;
    }
}
