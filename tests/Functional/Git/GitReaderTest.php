<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\CommitShaPrefix;
use App\Domain\Git\RefName;
use App\Domain\Git\RepoPath;
use App\Git\BareClone;
use App\Git\Blob;
use App\Git\GitFailed;
use App\Git\GitReader;
use App\Git\GitRunner;
use App\Git\LogCommit;
use App\Git\ObjectType;
use App\Git\TreeEntry;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GitReaderTest extends KernelTestCase
{
    use TemporaryDirectories;

    private const array FILES = [
        'README.md' => "Hello\nWorld\n",
        'empty.txt' => '',
        'image.png' => "\x89PNG\r\n\x1a\n\0\0\0\rIHDR",
        "line\nbreak.txt" => "A path with LF\n",
        'src/App.php' => "<?php\necho 1;",
    ];

    private GitReader $reader;

    private string $clone;

    private CommitSha $commit;

    protected function setUp(): void
    {
        $git = new GitRunner(new NullLogger());
        $source = GitRepositoryBuilder::init($this->temporaryDirectory());
        $this->commit = CommitSha::fromString($source->commit([...self::FILES, 'late-nul.txt' => str_repeat('a', 8000)."\0"]));
        $source->git('tag', '--annotate', '--message=Release', 'v1');
        $this->clone = $this->temporaryDirectory().'/clone.git';
        $bareClone = new BareClone($git);
        $bareClone->init($this->clone);
        $bareClone->fetch($this->clone, $source->url());
        $this->reader = new GitReader($git);
    }

    protected function tearDown(): void
    {
        $this->reader->reset();
        parent::tearDown();
    }

    #[TestDox('Lists tree entries at a commit')]
    public function testListsTreeEntries(): void
    {
        $root = $this->reader->lsTree($this->clone, $this->commit);
        $readme = $root[0];

        self::assertSame(['README.md', 'empty.txt', 'image.png', 'late-nul.txt', "line\nbreak.txt", 'src'], self::paths($root));
        self::assertEquals(['100644', ObjectType::Blob, 12], [$readme->mode, $readme->type, $readme->sizeBytes]);
        self::assertEquals([ObjectType::Tree, null], [$root[5]->type, $root[5]->sizeBytes]);
        self::assertSame(['src/App.php'], self::paths($this->reader->lsTree($this->clone, $this->commit, RepoPath::fromString('src'))));
        self::assertSame([...array_slice(self::paths($root), 0, 5), 'src/App.php'], self::paths($this->reader->lsTreeRecursive($this->clone, $this->commit)));
    }

    #[TestDox('Returns blob bytes equal to the source file, and null for a tree or a missing object')]
    public function testReturnsBlobBytes(): void
    {
        // The reads after the tree prove that the shared process skipped the tree bytes.
        self::assertNull($this->reader->readBlob($this->clone, BlobSha::fromString($this->reader->lsTree($this->clone, $this->commit)[5]->objectId)));

        foreach (['README.md', "line\nbreak.txt", 'src/App.php'] as $path) {
            self::assertSame(self::FILES[$path], $this->blobAt($path)->bytes);
        }

        self::assertNull($this->reader->readBlob($this->clone, BlobSha::fromString(str_repeat('0', 40))));
    }

    #[TestDox('Detects a binary file')]
    public function testDetectsBinaryFile(): void
    {
        self::assertTrue($this->blobAt('image.png')->isBinary());
        self::assertFalse($this->blobAt('README.md')->isBinary());
        self::assertFalse($this->blobAt('late-nul.txt')->isBinary(), 'A NUL after the first 8,000 bytes');
    }

    #[TestDox('Counts lines with and without a trailing newline')]
    public function testCountsLines(): void
    {
        self::assertSame(2, $this->blobAt('README.md')->lineCount());
        self::assertSame(2, $this->blobAt('src/App.php')->lineCount());
        self::assertSame(0, $this->blobAt('empty.txt')->lineCount());
    }

    #[TestDox('Resolves a branch, a tag, a SHA, and a prefix')]
    public function testResolvesRefs(): void
    {
        foreach (['main', 'v1', $this->commit->toString()] as $ref) {
            self::assertEquals($this->commit, $this->reader->resolveCommit($this->clone, RefName::fromString($ref)), $ref);
        }

        self::assertEquals($this->commit, $this->reader->resolvePrefix($this->clone, CommitShaPrefix::fromString(substr($this->commit->toString(), 0, 7))));
    }

    #[TestDox('An entry lookup gives null for a missing path and rethrows other git failures')]
    public function testEntryAtGivesNullForMissingPathAndRethrowsOtherFailures(): void
    {
        $entryAt = fn (string $path): ?TreeEntry => $this->reader->entryAt($this->clone, $this->commit, RepoPath::fromString($path));

        self::assertSame(['src/App.php', null, null, null], [$entryAt('src/App.php')?->path, $entryAt('src/Missing.php'), $entryAt('missing/App.php'), $entryAt('README.md/App.php')]);
        $this->expectException(GitFailed::class);
        $this->reader->entryAt($this->temporaryDirectory().'/missing.git', $this->commit, RepoPath::fromString('src/App.php'));
    }

    #[TestDox('The log keeps an empty subject at the end of the output')]
    public function testLogKeepsEmptySubject(): void
    {
        $git = new GitRunner(new NullLogger());
        $source = GitRepositoryBuilder::init($this->temporaryDirectory());
        $source->git('commit', '--quiet', '--allow-empty', '--allow-empty-message', '--message=');
        $head = $source->commit(message: 'Second');
        $clone = $this->temporaryDirectory().'/log.git';
        new BareClone($git)->init($clone);
        new BareClone($git)->fetch($clone, $source->url());

        $log = $this->reader->log($clone, CommitSha::fromString($head), 10);

        self::assertSame(['Second', ''], array_map(static fn (LogCommit $commit): string => $commit->subject, $log));
    }

    private function blobAt(string $path): Blob
    {
        $entry = array_find($this->reader->lsTreeRecursive($this->clone, $this->commit), static fn (TreeEntry $entry): bool => $entry->path === $path) ?? self::fail("No entry {$path}");
        $blob = $this->reader->readBlob($this->clone, BlobSha::fromString($entry->objectId));
        self::assertNotNull($blob, $path);

        return $blob;
    }

    /**
     * @param list<TreeEntry> $entries
     *
     * @return list<string>
     */
    private static function paths(array $entries): array
    {
        return array_map(static fn (TreeEntry $entry): string => $entry->path, $entries);
    }
}
