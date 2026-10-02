<?php

declare(strict_types=1);

namespace App\Tests\Functional\Source;

use App\Source\LocalRepositoryCandidate;
use App\Source\LocalRepositoryDiscovery;
use App\Source\LocalRepositoryListing;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LocalRepositoryDiscoveryTest extends KernelTestCase
{
    use EnvironmentVariables;
    use LocalSources;
    use TemporaryDirectories;

    private string $root;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->root = (string) realpath($this->localRepositoriesDirectory);
    }

    #[TestDox('Lists normal and bare repositories, sorted by name')]
    public function testListsNormalAndBareRepositories(): void
    {
        $this->newSource('zeta')->switch('develop');
        $billing = $this->newSource('billing');
        $billing->git('clone', '--bare', '--quiet', $billing->path, "{$this->root}/archive.git");
        $this->newSource('detached')->git('switch', '--detach', '--quiet');

        self::assertEquals(new LocalRepositoryListing([
            new LocalRepositoryCandidate('archive.git', true, 'main', "{$this->root}/archive.git"),
            new LocalRepositoryCandidate('billing', false, 'main', "{$this->root}/billing"),
            new LocalRepositoryCandidate('detached', false, null, "{$this->root}/detached"),
            new LocalRepositoryCandidate('zeta', false, 'develop', "{$this->root}/zeta"),
        ], false), self::discovery()->list());
    }

    #[TestDox('Ignores files, dot directories, worktree and submodule .git files, and symlinks')]
    public function testIgnoresEverythingElse(): void
    {
        $filesystem = new Filesystem();
        $billing = $this->newSource('billing');
        $this->newSource('.hidden');
        $filesystem->dumpFile("{$this->root}/notes.txt", "Notes\n");
        $filesystem->mkdir("{$this->root}/plain");
        $billing->git('worktree', 'add', '--quiet', "{$this->root}/worktree");
        $filesystem->dumpFile("{$this->root}/submodule/.git", "gitdir: ../billing/.git/modules/submodule\n");
        $filesystem->symlink("{$this->root}/billing", "{$this->root}/link-inside");
        $outside = GitRepositoryBuilder::init($this->temporaryDirectory().'/outside');
        $outside->commit();
        $filesystem->symlink($outside->path, "{$this->root}/link-outside");

        self::assertEquals(
            new LocalRepositoryListing([new LocalRepositoryCandidate('billing', false, 'main', "{$this->root}/billing")], false),
            self::discovery()->list(),
        );
        self::assertNull(self::discovery()->find('link-outside'));
    }

    #[TestDox('Stops at 1,000 candidates and reports truncated')]
    public function testStopsAtTheLimit(): void
    {
        $filesystem = new Filesystem();

        for ($number = 0; $number <= LocalRepositoryDiscovery::MAX_CANDIDATES; ++$number) {
            $bare = sprintf('%s/repository-%04d.git', $this->root, $number);
            $filesystem->mkdir(["{$bare}/objects", "{$bare}/refs"]);
            $filesystem->dumpFile("{$bare}/HEAD", "ref: refs/heads/main\n");
        }

        $listing = self::discovery()->list();

        self::assertTrue($listing->truncated);
        self::assertCount(LocalRepositoryDiscovery::MAX_CANDIDATES, $listing->candidates);
        self::assertSame('repository-0999.git', array_last($listing->candidates)->directory);
    }

    private static function discovery(): LocalRepositoryDiscovery
    {
        return self::getContainer()->get(LocalRepositoryDiscovery::class);
    }
}
