<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Domain\Anchor\SnapshotCapture;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\CommitShaPrefix;
use App\Domain\Git\RefName;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Git\GitCommandFailed;
use App\Git\GitReader;
use App\Git\GitRunner;
use App\Git\TenantDataPaths;
use App\Git\TreeEntry;
use App\Git\TreeEntryType;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class GitReaderTest extends KernelTestCase
{
    use TemporaryDirectories;

    private const string EMPTY_TREE = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';
    // Commit objects on the empty tree, found by search, whose SHA-1 IDs share the prefix 40364534.
    private const array AMBIGUOUS_COMMITS = [
        '40364534463d336cde60fe1db5874e9fbcf36289' => 'Ambiguous 10232',
        '403645345551588291beea4f58cbc3a1412ede74' => 'Ambiguous 18362',
    ];

    private TenantId $tenantId;
    private RepositoryId $repositoryId;
    private ?GitReader $reader = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = TenantId::generate();
        $this->repositoryId = RepositoryId::generate();
    }

    #[After]
    protected function resetReader(): void
    {
        $this->reader?->reset();
        $this->reader = null;
    }

    #[TestDox('Lists tree entries at a commit')]
    public function testListsTreeEntriesAtACommit(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $library = GitRepositoryBuilder::init($source->directory().'/vendor/lib');
        $library->commit(['lib.txt' => "lib\n"]);
        $filesystem = new Filesystem();
        $filesystem->dumpFile($source->directory().'/bin/tool', "#!/bin/sh\n");
        $filesystem->chmod($source->directory().'/bin/tool', 0o755);
        symlink('README.md', $source->directory().'/link');
        $files = ['README.md' => "# Title\n", 'src/App.php' => "<?php\n", 'src/Model/User.php' => "<?php\n\nfinal class User\n{\n}\n"];
        $commit = CommitSha::fromString($source->commit($files));
        $this->cloneOf($source);

        $readme = new TreeEntry('README.md', 'README.md', TreeEntryType::File, '100644', $source->resolve('HEAD:README.md'), \strlen($files['README.md']));
        $tool = new TreeEntry('bin/tool', 'tool', TreeEntryType::File, '100755', $source->resolve('HEAD:bin/tool'), \strlen("#!/bin/sh\n"));
        $link = new TreeEntry('link', 'link', TreeEntryType::Symlink, '120000', $source->resolve('HEAD:link'), \strlen('README.md'));
        $app = new TreeEntry('src/App.php', 'App.php', TreeEntryType::File, '100644', $source->resolve('HEAD:src/App.php'), \strlen($files['src/App.php']));
        $user = new TreeEntry('src/Model/User.php', 'User.php', TreeEntryType::File, '100644', $source->resolve('HEAD:src/Model/User.php'), \strlen($files['src/Model/User.php']));
        $submodule = new TreeEntry('vendor/lib', 'lib', TreeEntryType::Submodule, '160000', $library->resolve('HEAD'), null);

        self::assertEquals([
            $readme,
            self::directory('bin', $source),
            $link,
            self::directory('src', $source),
            self::directory('vendor', $source),
        ], $this->reader()->lsTree($this->tenantId, $this->repositoryId, $commit));
        self::assertEquals(
            [$app, self::directory('src/Model', $source)],
            $this->reader()->lsTree($this->tenantId, $this->repositoryId, $commit, RepoPath::fromString('src')),
        );
        self::assertEquals(
            [$readme, $tool, $link, $app, $user, $submodule],
            $this->reader()->lsTreeRecursive($this->tenantId, $this->repositoryId, $commit),
        );
        self::assertEquals(
            [$app, $user],
            $this->reader()->lsTreeRecursive($this->tenantId, $this->repositoryId, $commit, RepoPath::fromString('src')),
        );
        self::assertEquals($user, $this->reader()->entryAt($this->tenantId, $this->repositoryId, $commit, RepoPath::fromString('src/Model/User.php')));
        self::assertNull($this->reader()->entryAt($this->tenantId, $this->repositoryId, $commit, RepoPath::fromString('src/Missing.php')));
    }

    #[TestDox('Lists the recursive entries by path in byte order')]
    public function testListsTheRecursiveEntriesByPathInByteOrder(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $commit = CommitSha::fromString($source->commit(['a0.txt' => '0', 'a/b.txt' => 'b', 'a.txt' => '.', 'a-b/x' => 'x', 'B.txt' => 'B']));
        $this->cloneOf($source);

        $paths = array_map(
            static fn (TreeEntry $entry): string => $entry->path,
            $this->reader()->lsTreeRecursive($this->tenantId, $this->repositoryId, $commit),
        );

        self::assertSame(['B.txt', 'a-b/x', 'a.txt', 'a/b.txt', 'a0.txt'], $paths);
    }

    #[TestDox('Takes paths literally, without pathspec magic or wildcards')]
    public function testTakesPathsLiterally(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $commit = CommitSha::fromString($source->commit([':(glob)dir/*.txt' => 'magic', 'dir/a.txt' => 'a', '*.txt' => 'star', 'b.txt' => 'b']));
        $this->cloneOf($source);

        $bytesAt = fn (string $path): string => $this->reader()->blobAt($this->tenantId, $this->repositoryId, $commit, RepoPath::fromString($path))->content->bytes;

        self::assertSame('magic', $bytesAt(':(glob)dir/*.txt'));
        self::assertSame('star', $bytesAt('*.txt'));
        self::assertNull($this->reader()->entryAt($this->tenantId, $this->repositoryId, $commit, RepoPath::fromString('?.txt')));
        self::assertSame(
            [':(glob)dir/*.txt'],
            array_map(
                static fn (TreeEntry $entry): string => $entry->path,
                $this->reader()->lsTree($this->tenantId, $this->repositoryId, $commit, RepoPath::fromString(':(glob)dir')),
            ),
        );
    }

    #[TestDox('Refuses to list a path that is not a directory')]
    public function testRefusesToListAPathThatIsNotADirectory(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        GitRepositoryBuilder::init($source->directory().'/vendor/lib')->commit(['lib.txt' => "lib\n"]);
        $commit = CommitSha::fromString($source->commit(['README.md' => "# Title\n"]));
        $this->cloneOf($source);

        $listings = [
            'lsTree' => fn (RepoPath $directory): array => $this->reader()->lsTree($this->tenantId, $this->repositoryId, $commit, $directory),
            'lsTreeRecursive' => fn (RepoPath $directory): array => $this->reader()->lsTreeRecursive($this->tenantId, $this->repositoryId, $commit, $directory),
        ];
        foreach (['README.md', 'vendor/lib', 'missing', 'README.md/missing'] as $path) {
            foreach ($listings as $listingName => $listing) {
                try {
                    $listing(RepoPath::fromString($path));
                    self::fail(\sprintf('%s listed %s.', $listingName, $path));
                } catch (NotFound $notFound) {
                    self::assertSame(\sprintf('Directory %s was not found.', $path), $notFound->getMessage());
                }
            }
        }
    }

    #[TestDox('Returns blob bytes equal to the source file')]
    public function testReturnsBlobBytesEqualToTheSourceFile(): void
    {
        $files = [
            'text.txt' => "alpha\r\nbeta",
            'data.bin' => "\x00\x01\x02\xff\n\x00",
            'empty.txt' => '',
            'large.txt' => str_repeat("0123456789abcdef\n", 20_000),
        ];
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        symlink('text.txt', $source->directory().'/link');
        $commit = CommitSha::fromString($source->commit($files));
        $this->cloneOf($source);

        foreach ($files as $path => $bytes) {
            $blob = $this->reader()->blobAt($this->tenantId, $this->repositoryId, $commit, RepoPath::fromString($path));
            self::assertSame($bytes, $blob->content->bytes, $path);
            self::assertSame(\strlen($bytes), $blob->content->sizeBytes(), $path);
            self::assertSame($source->resolve('HEAD:'.$path), $blob->entry->sha, $path);
            self::assertSame(TreeEntryType::File, $blob->entry->type, $path);
            self::assertSame($bytes, $this->reader()->readBlob($this->tenantId, $this->repositoryId, BlobSha::fromString($blob->entry->sha))->bytes, $path);
        }
        $link = $this->reader()->blobAt($this->tenantId, $this->repositoryId, $commit, RepoPath::fromString('link'));
        self::assertSame(TreeEntryType::Symlink, $link->entry->type);
        self::assertSame('text.txt', $link->content->bytes);
    }

    #[TestDox('A path or an object that is not a blob gives not_found')]
    public function testAPathOrAnObjectThatIsNotABlobGivesNotFound(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        GitRepositoryBuilder::init($source->directory().'/vendor/lib')->commit(['lib.txt' => "lib\n"]);
        $commit = CommitSha::fromString($source->commit(['src/App.php' => "<?php\n"]));
        $this->cloneOf($source);

        foreach (['src', 'vendor/lib', 'src/Missing.php'] as $path) {
            try {
                $this->reader()->blobAt($this->tenantId, $this->repositoryId, $commit, RepoPath::fromString($path));
                self::fail(\sprintf('%s was read as a blob.', $path));
            } catch (NotFound $notFound) {
                self::assertSame(\sprintf('File %s was not found.', $path), $notFound->getMessage());
            }
        }
        foreach ([$source->resolve('HEAD:src'), $commit->toString(), str_repeat('0', 40)] as $sha) {
            try {
                $this->reader()->readBlob($this->tenantId, $this->repositoryId, BlobSha::fromString($sha));
                self::fail(\sprintf('%s was read as a blob.', $sha));
            } catch (NotFound $notFound) {
                self::assertSame(\sprintf('Blob %s was not found.', $sha), $notFound->getMessage());
            }
        }
        self::assertSame("<?php\n", $this->reader()->blobAt($this->tenantId, $this->repositoryId, $commit, RepoPath::fromString('src/App.php'))->content->bytes);
    }

    #[TestDox('Detects a binary file')]
    public function testDetectsABinaryFile(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $commit = CommitSha::fromString($source->commit([
            'text.txt' => "alpha\nbeta\n",
            'nul-in-byte-8000.bin' => str_repeat('a', 7_999)."\0",
            'nul-in-byte-8001.txt' => str_repeat('a', 8_000)."\0",
        ]));
        $this->cloneOf($source);

        $isBinary = fn (string $path): bool => $this->reader()->blobAt($this->tenantId, $this->repositoryId, $commit, RepoPath::fromString($path))->content->isBinary();

        self::assertFalse($isBinary('text.txt'));
        self::assertTrue($isBinary('nul-in-byte-8000.bin'));
        self::assertFalse($isBinary('nul-in-byte-8001.txt'));
    }

    #[TestDox('Counts lines with and without a trailing newline')]
    public function testCountsLinesWithAndWithoutATrailingNewline(): void
    {
        $expectedLineCounts = ['trailing.txt' => 2, 'no-trailing.txt' => 2, 'crlf.txt' => 2, 'blank-line.txt' => 1, 'empty.txt' => 0];
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $commit = CommitSha::fromString($source->commit([
            'trailing.txt' => "one\ntwo\n",
            'no-trailing.txt' => "one\ntwo",
            'crlf.txt' => "one\r\ntwo\r\n",
            'blank-line.txt' => "\n",
            'empty.txt' => '',
        ]));
        $this->cloneOf($source);

        foreach ($expectedLineCounts as $path => $expectedLineCount) {
            $content = $this->reader()->blobAt($this->tenantId, $this->repositoryId, $commit, RepoPath::fromString($path))->content;
            self::assertSame($expectedLineCount, $content->lineCount(), $path);
            self::assertCount($expectedLineCount, SnapshotCapture::fileLines($content->bytes), $path);
        }
    }

    #[TestDox('Resolves a branch, a tag, a SHA, and a prefix')]
    public function testResolvesABranchATagAShaAndAPrefix(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $mainTip = $source->commit(['a.txt' => "a\n"]);
        $source->tag('v1.0')->branch('feature')->checkout('feature');
        $featureTip = $source->commit(['b.txt' => "b\n"]);
        $source->tag('v1.1', message: 'Release 1.1')->checkout('main');
        $this->cloneOf($source);

        $resolve = fn (string $ref): string => $this->reader()->resolveCommit($this->tenantId, $this->repositoryId, RefName::fromString($ref))->toString();

        self::assertSame($mainTip, $resolve('main'));
        self::assertSame($mainTip, $resolve('HEAD'));
        self::assertSame($featureTip, $resolve('feature'));
        self::assertSame($featureTip, $resolve('refs/heads/feature'));
        self::assertSame($mainTip, $resolve('v1.0'));
        self::assertNotSame($featureTip, $source->resolve('v1.1'));
        self::assertSame($featureTip, $resolve('v1.1'));
        self::assertSame($featureTip, $resolve($featureTip));
        self::assertSame($featureTip, $resolve(substr($featureTip, 0, 7)));
        self::assertSame(
            $mainTip,
            $this->reader()->resolvePrefix($this->tenantId, $this->repositoryId, CommitShaPrefix::fromString(substr($mainTip, 0, 10)))->toString(),
        );
        self::assertSame(
            $featureTip,
            $this->reader()->resolvePrefix($this->tenantId, $this->repositoryId, CommitShaPrefix::fromString($featureTip))->toString(),
        );
    }

    #[TestDox('Resolves a prefix to the commit, not to a branch with the prefix as its name')]
    public function testResolvesAPrefixToTheCommitNotToABranch(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $firstCommit = $source->commit(['a.txt' => "a\n"]);
        $secondCommit = $source->commit(['b.txt' => "b\n"]);
        $prefix = substr($firstCommit, 0, 7);
        $source->branch($prefix, $secondCommit);
        $this->cloneOf($source);

        self::assertSame($secondCommit, $this->reader()->resolveCommit($this->tenantId, $this->repositoryId, RefName::fromString($prefix))->toString());
        self::assertSame($firstCommit, $this->reader()->resolvePrefix($this->tenantId, $this->repositoryId, CommitShaPrefix::fromString($prefix))->toString());
    }

    #[TestDox('A prefix of two commits is invalid input, a longer unique prefix resolves')]
    public function testAPrefixOfTwoCommitsIsInvalidInput(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $source->commit(['a.txt' => "a\n"]);
        self::assertSame(self::EMPTY_TREE, trim($source->git('hash-object', '-w', '-t', 'tree', '/dev/null')));
        $objectDirectory = $this->createTemporaryDirectory('objects');
        foreach (self::AMBIGUOUS_COMMITS as $sha => $message) {
            $objectFile = $objectDirectory.'/commit';
            new Filesystem()->dumpFile($objectFile, 'tree '.self::EMPTY_TREE."\nauthor Test Author <author@example.com> 1767225600 +0000\ncommitter Test Committer <committer@example.com> 1767225600 +0000\n\n{$message}\n");
            self::assertSame($sha, trim($source->git('hash-object', '-w', '-t', 'commit', $objectFile)));
            $source->updateRef('refs/tags/'.str_replace(' ', '-', strtolower($message)), $sha);
        }
        $this->cloneOf($source);

        foreach (['4036453', '40364534'] as $prefix) {
            try {
                $this->reader()->resolvePrefix($this->tenantId, $this->repositoryId, CommitShaPrefix::fromString($prefix), 'at');
                self::fail(\sprintf('%s was resolved.', $prefix));
            } catch (InvalidInput $invalidInput) {
                self::assertSame(['violations' => [['field' => 'at', 'message' => 'matches more than one commit']]], $invalidInput->details());
            }
            try {
                $this->reader()->resolveCommit($this->tenantId, $this->repositoryId, RefName::fromString($prefix));
                self::fail(\sprintf('%s was resolved.', $prefix));
            } catch (InvalidInput $invalidInput) {
                self::assertSame(['violations' => [['field' => 'ref', 'message' => 'matches more than one commit']]], $invalidInput->details());
            }
        }
        self::assertSame(
            '403645345551588291beea4f58cbc3a1412ede74',
            $this->reader()->resolveCommit($this->tenantId, $this->repositoryId, RefName::fromString('403645345'))->toString(),
        );
    }

    #[TestDox('Reports whether the clone has a commit')]
    public function testReportsWhetherTheCloneHasACommit(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $commit = $source->commit(['a.txt' => "a\n"]);
        $this->cloneOf($source);

        $hasCommit = fn (string $sha): bool => $this->reader()->hasCommit($this->tenantId, $this->repositoryId, CommitSha::fromString($sha));

        self::assertTrue($hasCommit($commit));
        self::assertFalse($hasCommit($source->resolve('HEAD:a.txt')));
        self::assertFalse($hasCommit(str_repeat('0', 40)));
    }

    #[TestDox('A clone directory that is no repository fails instead of hiding the commit')]
    public function testACloneDirectoryThatIsNoRepositoryFails(): void
    {
        $paths = self::getContainer()->get(TenantDataPaths::class);
        $this->removeAfterTest($paths->tenantDirectory($this->tenantId));
        new Filesystem()->mkdir($paths->cloneDirectory($this->tenantId, $this->repositoryId));
        $sha = str_repeat('a', 40);

        $readers = [
            'hasCommit' => fn (): mixed => $this->reader()->hasCommit($this->tenantId, $this->repositoryId, CommitSha::fromString($sha)),
            'resolveCommit' => fn (): mixed => $this->reader()->resolveCommit($this->tenantId, $this->repositoryId, RefName::fromString($sha)),
        ];
        foreach ($readers as $readerName => $read) {
            try {
                $read();
                self::fail(\sprintf('%s did not fail.', $readerName));
            } catch (GitCommandFailed $failure) {
                self::assertSame(128, $failure->exitCode, $readerName);
                self::assertStringContainsString('not a git repository', $failure->errorOutput, $readerName);
            }
        }
    }

    #[TestDox('Reads all blobs of a clone through one cat-file process until reset')]
    public function testReadsAllBlobsOfACloneThroughOneCatFileProcess(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $commit = CommitSha::fromString($source->commit(['a.txt' => "a\n", 'b.txt' => "b\n", 'c.txt' => "c\n"]));
        $this->cloneOf($source);

        foreach (['a.txt', 'b.txt', 'c.txt'] as $path) {
            $this->reader()->blobAt($this->tenantId, $this->repositoryId, $commit, RepoPath::fromString($path));
        }
        self::assertSame(1, self::startedCatFileProcesses());

        $this->reader()->reset();
        self::assertSame("a\n", $this->reader()->blobAt($this->tenantId, $this->repositoryId, $commit, RepoPath::fromString('a.txt'))->content->bytes);
        self::assertSame(2, self::startedCatFileProcesses());
    }

    private function reader(): GitReader
    {
        return $this->reader ??= new GitReader(self::getContainer()->get(GitRunner::class), self::getContainer()->get(TenantDataPaths::class));
    }

    /**
     * A bare clone at the ID path, made by the test builder, not by the code under test.
     */
    private function cloneOf(GitRepositoryBuilder $source): void
    {
        $paths = self::getContainer()->get(TenantDataPaths::class);
        $this->removeAfterTest($paths->tenantDirectory($this->tenantId));
        $source->git('clone', '--bare', '--quiet', $source->fileUrl(), $paths->cloneDirectory($this->tenantId, $this->repositoryId));
    }

    private static function directory(string $path, GitRepositoryBuilder $source): TreeEntry
    {
        return new TreeEntry($path, basename($path), TreeEntryType::Directory, '040000', $source->resolve('HEAD:'.$path), null);
    }

    /**
     * The test environment adds a TestHandler to the git channel.
     */
    private static function startedCatFileProcesses(): int
    {
        $logger = self::getContainer()->get('monolog.logger.git');
        $testHandlers = array_values(array_filter($logger->getHandlers(), static fn (HandlerInterface $handler): bool => $handler instanceof TestHandler));
        self::assertCount(1, $testHandlers);

        return \count(array_filter(
            $testHandlers[0]->getRecords(),
            static fn (LogRecord $record): bool => str_starts_with($record->message, 'git cat-file --batch started in '),
        ));
    }
}
