<?php

declare(strict_types=1);

namespace App\Tests\Functional\Index;

use App\Application\Query\IndexStatus;
use App\Application\ReviewCommands;
use App\Domain\Git\BlobSha;
use App\Domain\Git\RefName;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\ReviewId;
use App\Domain\Review\IndexState;
use App\Entity\Repository;
use App\Entity\Review;
use App\Git\GitReader;
use App\Index\IndexSql;
use App\Index\PhpSymbolExtractor;
use App\Message\IndexReview;
use App\Repository\ReviewRepository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use App\Tests\Support\Source\LocalSources;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;

final class IndexReviewHandlerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use EnvironmentVariables;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewDataFixtures;
    use TemporaryDirectories;

    private const string CLASS_WITH_METHOD = "<?php\n\nfinal class Shared\n{\n    public function run(): void\n    {\n    }\n}\n";

    private GitRepositoryBuilder $source;

    private Repository $repository;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        self::enterNewTenant();
        $this->repository = self::connect();
        self::runQueuedMessages();
    }

    #[TestDox('Blobs shared with an earlier review are skipped')]
    public function testSharedBlobsAreSkipped(): void
    {
        $this->source->commit(['src/Shared.php' => self::CLASS_WITH_METHOD, 'src/Changed.php' => "<?php\n\nfinal class Changed\n{\n}\n"]);
        $first = $this->startIndexedReview();
        // A parse of the shared blob would count its two symbols again.
        self::connection()->executeStatement('DELETE FROM symbol WHERE blob_sha = ?', [$this->source->revParse('main:src/Shared.php')]);
        $this->source->commit(['src/Changed.php' => "<?php\n\nfinal class Changed\n{\n    public const int VERSION = 2;\n}\n"]);

        $second = $this->startIndexedReview();

        self::assertSame(3, self::indexStatus($first)->symbolsTotal);
        self::assertSame(2, self::indexStatus($second)->symbolsTotal, 'Changed and Changed::VERSION, and none of the shared blob.');
        self::assertSame(3, self::rowCount('indexed_blob'));
    }

    #[TestDox('Two reviews at one commit share the indexed files')]
    public function testReviewsAtOneCommitShareTheIndexedFiles(): void
    {
        $this->source->commit(['src/Shared.php' => self::CLASS_WITH_METHOD, 'docs/notes.md' => "Notes\n"]);
        $first = self::startReview($this->repository->getId());
        $second = self::startReview($this->repository->getId());
        self::runQueuedMessages();

        self::assertSame(['README.md', 'docs/notes.md', 'src/Shared.php'], self::connection()->fetchFirstColumn('SELECT path FROM indexed_file ORDER BY path'));

        foreach ([$first, $second] as $reviewId) {
            $status = self::indexStatus($reviewId);
            self::assertSame([IndexState::Ready, 3, 3, 2], [$status->state, $status->filesTotal, $status->filesDone, $status->symbolsTotal]);
        }
    }

    #[TestDox('Two index jobs at one commit write each row once')]
    public function testTwoJobsAtOneCommitWriteEachRowOnce(): void
    {
        $this->source->commit(['src/Shared.php' => self::CLASS_WITH_METHOD]);
        $reviewId = self::startReview($this->repository->getId());
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new IndexReview($reviewId));
        self::runQueuedMessages();
        $blobSha = $this->source->revParse('main:src/Shared.php');

        // The writer that loses the race on the blob row stores no symbols.
        $table = new PhpSymbolExtractor()->extract(self::CLASS_WITH_METHOD);
        $stored = self::getContainer()->get(IndexSql::class)->insertBlob($this->repository->getTenantId(), $this->repository->getId(), BlobSha::fromString($blobSha), $table);

        self::assertFalse($stored);
        self::assertSame([2, 1, 2], [self::rowCount('indexed_file'), self::rowCount('indexed_blob'), self::rowCount('symbol')]);
        self::assertSame(IndexState::Ready, self::indexStatus($reviewId)->state);
    }

    #[TestDox('A job after a crashed job completes the file list')]
    public function testJobAfterCrashedJobCompletesTheFileList(): void
    {
        $this->source->commit(['src/A.php' => "<?php\n\nfunction a(): void\n{\n}\n", 'src/B.php' => "<?php\n\nfunction b(): void\n{\n}\n"]);
        $reviewId = self::startReview($this->repository->getId());
        $review = self::getContainer()->get(ReviewRepository::class)->findFresh($reviewId) ?? self::fail('No review.');
        // The crashed job wrote the first path, and left the state running.
        [$readme] = self::getContainer()->get(GitReader::class)->lsTreeRecursive(self::clonePath($this->repository), $review->getCommitSha());
        self::getContainer()->get(IndexSql::class)->insertFiles($this->repository->getTenantId(), $this->repository->getId(), $review->getCommitSha(), [$readme]);
        $review->startIndex(new \DateTimeImmutable());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::runQueuedMessages();

        self::assertSame(['README.md', 'src/A.php', 'src/B.php'], self::connection()->fetchFirstColumn('SELECT path FROM indexed_file ORDER BY path'));
        $status = self::indexStatus($reviewId);
        self::assertSame([IndexState::Ready, 3, 2], [$status->state, $status->filesTotal, $status->symbolsTotal]);
    }

    #[TestDox('A blob without symbols and a blob with a parse error are parsed once')]
    public function testBlobsWithoutSymbolsAreParsedOnce(): void
    {
        $this->source->commit(['src/Empty.php' => "<?php\n\n// Nothing to index.\n", 'src/Broken.php' => "<?php\n\nfinal class Broken\n{\n"]);
        $first = $this->startIndexedReview();
        $parseErrors = self::connection()->fetchAllKeyValue('SELECT blob_sha, parse_error FROM indexed_blob');
        // One stored symbol per blob, so a parse that ran again would count 0 instead of 1.
        self::connection()->executeStatement("INSERT INTO symbol (tenant_id, repository_id, blob_sha, kind, fqn, fqn_sha256, name, start_line, end_line, declaration_start_line) SELECT tenant_id, repository_id, blob_sha, 'class', 'Marker', '".hash('sha256', 'Marker')."', 'Marker', 1, 1, 1 FROM indexed_blob");
        $this->source->commit(['docs/notes.md' => "Notes\n"]);

        $second = $this->startIndexedReview();

        self::assertEquals([$this->source->revParse('main:src/Broken.php') => true, $this->source->revParse('main:src/Empty.php') => false], $parseErrors);
        self::assertSame([0, 2], [self::indexStatus($first)->symbolsTotal, self::indexStatus($second)->symbolsTotal]);
    }

    #[TestDox('A submodule entry, a binary PHP file, and a PHP file above 1 MiB are listed but not parsed')]
    public function testEntriesThatAreNotRenderableAreListedButNotParsed(): void
    {
        $this->source->commit([
            'src/Binary.php' => "<?php\n\nfinal class Binary\n{\n}\n\0",
            'src/Large.php' => "<?php\n\nfinal class Large\n{\n}\n".str_repeat("// Padding.\n", 100_000),
        ]);
        $this->source->git('update-index', '--add', '--cacheinfo', '160000,'.str_repeat('5', 40).',lib/Plugin.php');
        $this->source->git('commit', '--quiet', '--message=Submodule');

        $reviewId = $this->startIndexedReview();

        self::assertSame([
            ['path' => 'README.md', 'mode' => '100644'],
            ['path' => 'lib/Plugin.php', 'mode' => '160000'],
            ['path' => 'src/Binary.php', 'mode' => '100644'],
            ['path' => 'src/Large.php', 'mode' => '100644'],
        ], self::connection()->fetchAllAssociative('SELECT path, mode FROM indexed_file ORDER BY path'));
        self::assertSame(0, self::rowCount('indexed_blob'));
        $status = self::indexStatus($reviewId);
        self::assertSame([IndexState::Ready, 4, 0], [$status->state, $status->filesTotal, $status->symbolsTotal]);
    }

    #[TestDox('A path longer than a btree entry allows is listed and parsed')]
    public function testLongPathIsListed(): void
    {
        $this->source->commit(['src/A.php' => "<?php\n\nfunction a(): void\n{\n}\n"]);
        // About 2,800 bytes, above the btree entry limit of about 2,700. The index entry gets no file in the work tree.
        $longPath = implode('/', array_fill(0, 14, str_repeat('d', 200))).'/Long.php';
        $blobSha = trim($this->source->git('hash-object', '-w', 'src/A.php'));
        $this->source->git('update-index', '--add', '--cacheinfo', "100644,{$blobSha},{$longPath}");
        $this->source->git('commit', '--quiet', '--message=Long path');

        $reviewId = $this->startIndexedReview();

        self::assertContains($longPath, self::connection()->fetchFirstColumn('SELECT path FROM indexed_file'));
        $status = self::indexStatus($reviewId);
        self::assertSame([IndexState::Ready, 3, 2], [$status->state, $status->filesTotal, $status->symbolsTotal]);
    }

    #[TestDox('A failed job records failed with a message that holds no server path')]
    public function testFailedJobRecordsFailed(): void
    {
        $reviewId = self::startReview($this->repository->getId());
        new Filesystem()->remove(self::clonePath($this->repository));

        self::runQueuedMessages();

        $status = self::indexStatus($reviewId);
        self::assertSame([IndexState::Failed, 'The index job failed.'], [$status->state, $status->error]);
        self::assertStringNotContainsString(self::clonePath($this->repository), (string) $status->error);
        self::assertNotNull($status->finishedAt);
    }

    #[TestDox('The state becomes ready with the progress counters set')]
    public function testStateBecomesReadyWithTheCounters(): void
    {
        self::mockTime('2026-10-02 08:00:00');
        $this->source->commit(['src/Shared.php' => self::CLASS_WITH_METHOD, 'docs/notes.md' => "Notes\n"]);
        $reviewId = self::startReview($this->repository->getId());
        self::assertSame(IndexState::Pending, self::indexStatus($reviewId)->state);

        self::runQueuedMessages();

        $time = new \DateTimeImmutable('2026-10-02 08:00:00');
        $status = self::indexStatus($reviewId);
        self::assertSame([IndexState::Ready, 3, 3, 2, null], [$status->state, $status->filesTotal, $status->filesDone, $status->symbolsTotal, $status->error]);
        self::assertEquals([$time, $time], [$status->startedAt, $status->finishedAt]);
    }

    private function startIndexedReview(): ReviewId
    {
        $reviewId = self::startReview($this->repository->getId());
        self::runQueuedMessages();

        return $reviewId;
    }

    private static function startReview(RepositoryId $repositoryId): ReviewId
    {
        return self::getContainer()->get(ReviewCommands::class)->start($repositoryId, RefName::fromString('main'), null, RequestScenario::user());
    }

    private static function indexStatus(ReviewId $reviewId): IndexStatus
    {
        return IndexStatus::of(self::review($reviewId));
    }

    private static function review(ReviewId $reviewId): Review
    {
        return self::getContainer()->get(ReviewRepository::class)->findFresh($reviewId) ?? self::fail("No review {$reviewId}.");
    }

    private static function rowCount(string $table): int
    {
        return (int) self::connection()->fetchOne("SELECT COUNT(*) FROM {$table}");
    }

    private static function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
