<?php

declare(strict_types=1);

namespace App\Tests\Functional\Source;

use App\Domain\Identity\RepositoryId;
use App\Entity\Repository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * D92. With `gc.autoPackLimit` 1, two packs make `gc --auto` repack them into one.
 */
final class CloneGarbageTest extends KernelTestCase
{
    use EnvironmentVariables;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewDataFixtures;
    use TemporaryDirectories;

    private GitRepositoryBuilder $source;

    private RepositoryId $repositoryId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        self::enterNewTenant();
        $this->repositoryId = self::connect()->getId();
        self::runQueuedMessages();
    }

    #[TestDox('The fetch job runs gc --auto, and a synchronous fetch does not')]
    public function testFetchJobCollectsGarbage(): void
    {
        $repository = self::freshRepository($this->repositoryId);
        self::cloneGit($repository, 'config', 'gc.autoPackLimit', '1');
        self::cloneGit($repository, 'repack', '-d', '--quiet');
        $extra = $this->temporaryDirectory().'/extra.txt';
        new Filesystem()->dumpFile($extra, "Second pack\n");
        // Repack without -a packs only reachable loose objects.
        self::cloneGit($repository, 'update-ref', 'refs/test/extra', trim(self::cloneGit($repository, 'hash-object', '-w', $extra)));
        self::cloneGit($repository, 'repack', '-d', '--quiet');
        $this->source->commit(['later.txt' => "Later\n"]);

        self::fetchRepository()->fetchNow($this->repositoryId);
        $packsAfterSynchronousFetch = self::packs($repository);
        self::fetchRepository()->request($this->repositoryId);
        self::runQueuedMessages();

        self::assertSame([2, 1], [$packsAfterSynchronousFetch, self::packs($repository)]);
    }

    private static function packs(Repository $repository): int
    {
        self::assertSame(1, preg_match('/^packs: (\d+)$/m', self::cloneGit($repository, 'count-objects', '-v'), $packs));

        return (int) $packs[1];
    }
}
