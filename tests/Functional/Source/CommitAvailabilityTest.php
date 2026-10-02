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

final class CommitAvailabilityTest extends KernelTestCase
{
    use EnvironmentVariables;
    use InvalidInputAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewDataFixtures;
    use TemporaryDirectories;

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
        $this->source->branchCommitsWithAmbiguousPrefix();
        $this->connectAndClone();

        self::assertInvalidInput(['commit'], fn (): ?CommitSha => self::commitAvailability()->ensureAvailable($this->repositoryId, CommitShaPrefix::fromString(GitRepositoryBuilder::AMBIGUOUS_PREFIX)));
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

    private static function commitAvailability(): CommitAvailability
    {
        return self::getContainer()->get(CommitAvailability::class);
    }
}
