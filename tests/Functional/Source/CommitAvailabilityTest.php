<?php

declare(strict_types=1);

namespace App\Tests\Functional\Source;

use App\Application\CommitAvailability;
use App\Application\Event\RepositoryFetched;
use App\Domain\Git\CommitSha;
use App\Domain\Git\CommitShaPrefix;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\InvalidInputAssertions;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CommitAvailabilityTest extends KernelTestCase
{
    use EnvironmentVariables;
    use InvalidInputAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewDataFixtures;
    use TemporaryDirectories;

    /** The commit objects "Commit 27811" and "Commit 29253" on the empty tree, see {@see craftCommit()}, share this prefix. */
    private const string AMBIGUOUS_PREFIX = '0806e1e';

    private GitRepositoryBuilder $source;

    private TenantId $tenantId;

    private RepositoryId $repositoryId;

    /** @var list<RepositoryFetched> */
    private array $fetches = [];

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
    }

    #[TestDox('A new source commit becomes available after one fetch')]
    public function testNewSourceCommitBecomesAvailable(): void
    {
        $this->connectAndClone();
        $newHead = $this->source->commit(['later.txt' => "Later\n"]);

        $available = self::commitAvailability()->ensureAvailable($this->repositoryId, CommitShaPrefix::fromString($newHead));

        self::assertEquals(CommitSha::fromString($newHead), $available);
        self::assertCount(1, $this->fetches);
    }

    #[TestDox('An unknown SHA is null after exactly one fetch')]
    public function testUnknownShaIsNullAfterOneFetch(): void
    {
        $this->connectAndClone();

        $available = self::commitAvailability()->ensureAvailable($this->repositoryId, CommitShaPrefix::fromString(str_repeat('d', 40)));

        self::assertNull($available);
        self::assertCount(1, $this->fetches);
    }

    #[TestDox('A prefix resolves to the full SHA')]
    public function testPrefixResolvesToTheFullSha(): void
    {
        $head = $this->source->revParse('HEAD');
        $this->connectAndClone();

        $available = self::commitAvailability()->ensureAvailable($this->repositoryId, CommitShaPrefix::fromString(substr($head, 0, 7)));

        self::assertEquals(CommitSha::fromString($head), $available);
        self::assertCount(0, $this->fetches);
    }

    #[TestDox('A fetch dispatches RepositoryFetched')]
    public function testFetchDispatchesRepositoryFetched(): void
    {
        $previousHead = $this->source->revParse('HEAD');
        $this->connectAndClone();
        $newHead = $this->source->commit(['later.txt' => "Later\n"]);

        self::commitAvailability()->ensureAvailable($this->repositoryId, CommitShaPrefix::fromString($newHead));

        self::assertEquals(
            [new RepositoryFetched($this->tenantId, $this->repositoryId, CommitSha::fromString($previousHead), CommitSha::fromString($newHead))],
            $this->fetches,
        );
    }

    #[TestDox('A prefix of two commits gives invalid_input without a fetch')]
    public function testAmbiguousPrefixIsInvalidInput(): void
    {
        $this->source->git('branch', 'first', $this->craftCommit(27811));
        $this->source->git('branch', 'second', $this->craftCommit(29253));
        $this->connectAndClone();

        self::assertInvalidInput(['commit'], fn (): ?CommitSha => self::commitAvailability()->ensureAvailable($this->repositoryId, CommitShaPrefix::fromString(self::AMBIGUOUS_PREFIX)));
        self::assertCount(0, $this->fetches);
    }

    /**
     * Records the fetches from now on.
     */
    private function connectAndClone(): void
    {
        $this->tenantId = self::enterNewTenant();
        $this->repositoryId = self::connect()->getId();
        self::runQueuedMessages();
        self::getContainer()->get('event_dispatcher')->addListener(RepositoryFetched::class, function (RepositoryFetched $event): void {
            $this->fetches[] = $event;
        });
    }

    /**
     * A commit with fixed dates, so its SHA is known in advance.
     */
    private function craftCommit(int $number): string
    {
        $directory = $this->temporaryDirectory();
        $filesystem = new Filesystem();
        $filesystem->dumpFile("{$directory}/empty", '');
        $emptyTree = trim($this->source->git('hash-object', '-t', 'tree', '-w', "{$directory}/empty"));
        $filesystem->dumpFile("{$directory}/commit", "tree {$emptyTree}\n"
            ."author Test <test@example.invalid> 1700000000 +0000\ncommitter Test <test@example.invalid> 1700000000 +0000\n\nCommit {$number}\n");

        return trim($this->source->git('hash-object', '-t', 'commit', '-w', "{$directory}/commit"));
    }

    private static function commitAvailability(): CommitAvailability
    {
        return self::getContainer()->get(CommitAvailability::class);
    }
}
