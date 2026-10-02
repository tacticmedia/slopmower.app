<?php

declare(strict_types=1);

namespace App\Tests\Functional\Source;

use App\Domain\Repository\SyncState;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

/**
 * The php container runs as root, so a read-only check by permissions proves nothing.
 */
final class SourceUntouchedTest extends KernelTestCase
{
    use EnvironmentVariables;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewDataFixtures;
    use TemporaryDirectories;

    #[TestDox('File hashes and modification times of the source are equal before and after a fetch')]
    public function testCloneAndFetchLeaveTheSourceUntouched(): void
    {
        $this->useTemporaryLocalSources();
        $source = $this->newSource();
        $source->git('tag', 'v1');
        self::enterNewTenant();
        $before = self::snapshot($source->path);

        $repositoryId = self::connect()->getId();
        self::runQueuedMessages();
        self::fetchRepository()->fetchNow($repositoryId);

        self::assertSame(SyncState::Idle, self::freshRepository($repositoryId)->getSyncState());
        self::assertSame($before, self::snapshot($source->path));
    }

    /**
     * @return array<string, array{?string, int}> The SHA-1 of each file, null for a directory, and the modification time
     */
    private static function snapshot(string $directory): array
    {
        clearstatcache();
        $entries = ['.' => [null, (int) filemtime($directory)]];

        foreach (Finder::create()->in($directory)->ignoreDotFiles(false)->ignoreVCS(false)->sortByName() as $entry) {
            $entries[$entry->getRelativePathname()] = [$entry->isFile() ? (string) sha1_file($entry->getPathname()) : null, $entry->getMTime()];
        }

        return $entries;
    }
}
