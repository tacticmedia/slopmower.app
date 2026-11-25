<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Domain\Repository\CodeAccess;
use App\Domain\Repository\RepositoryName;
use App\Entity\Repository;
use App\Git\TenantDataPaths;
use App\Message\FetchRepositoryJob;
use App\Repository\RepositoryRepository;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;

/**
 * I12 of docs/specs/rest-api-issues.md (D132, D138), for the issue SM-1.
 */
final class IssueCommitsTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const string SCHEMA = 'issue.schema.json#/$defs/IssueCommits';

    private KernelBrowser $client;

    private GitRepositoryBuilder $source;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        self::mockTime('2026-11-25 09:00:00');
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        $this->client = static::createClient();
        $this->tenantId = self::signIn($this->client)->getTenantId();
        self::storeProject($this->tenantId, 'SM');
        self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/projects/SM/issues', ['title' => 'Round invoice totals once', 'category' => 'change']), 201, 'issue.schema.json');
    }

    #[TestDox('A commit of the default branch with the key in its message is listed with its repository, its time, and no branch')]
    public function testKeyInMessage(): void
    {
        $sha = $this->source->commit(message: "feat: SM-1 round totals once\n\nThe body is not the subject.");
        $this->connectTo('SM');

        $commits = $this->commits();

        self::assertSame([[
            'repository' => 'billing',
            'sha' => $sha,
            'subject' => 'feat: SM-1 round totals once',
            'authorName' => 'Test',
            'committedAt' => gmdate('Y-m-d\TH:i:s\Z', (int) trim($this->source->git('log', '-1', '--format=%ct', $sha))),
            'onDefaultBranch' => true,
            'branch' => null,
            'htmlUrl' => null,
        ]], $commits['items']);
    }

    #[TestDox('A commit that names SM-12 or ASM-1 is not listed for SM-1')]
    public function testOtherKeyInMessage(): void
    {
        $this->source->commit(message: 'feat: SM-12 another issue');
        $this->source->commit(message: 'chore: ASM-1 is no key');
        $this->connectTo('SM');

        self::assertSame([], $this->commits()['items']);
    }

    #[TestDox('The commits of a branch named with the key are listed without the key in their message and without the commits of the default branch')]
    public function testKeyInBranchName(): void
    {
        $onBranch = $this->source->switch('sm-1-round-totals')->commit(message: 'Round once');
        $this->source->switch('main')->switch('sm-12-other')->commit(message: 'Other');
        $this->source->switch('main');
        $this->connectTo('SM');

        $commits = $this->commits();

        self::assertSame([[$onBranch, false, 'sm-1-round-totals']], array_map(static fn (array $item): array => [$item['sha'], $item['onDefaultBranch'], $item['branch']], $commits['items']));
    }

    #[TestDox('A commit of another branch with the key in its message is listed with that branch')]
    public function testKeyInMessageOnAnotherBranch(): void
    {
        $sha = $this->source->switch('feature-x')->commit(message: 'feat: SM-1 on a feature branch');
        $this->source->switch('main');
        $this->connectTo('SM');

        self::assertSame([[$sha, false, 'feature-x']], array_map(static fn (array $item): array => [$item['sha'], $item['onDefaultBranch'], $item['branch']], $this->commits()['items']));
    }

    #[TestDox('A commit of a branch named with the key and of another branch names the branch with the key')]
    public function testNamedBranchWins(): void
    {
        $sha = $this->source->switch('sm-1-round')->commit(message: 'feat: SM-1 round once');
        $this->source->commit(message: 'Next');
        // The tip of feature-x, so git names feature-x as the branch of the commit in the search by message.
        $this->source->git('branch', 'feature-x', $sha);
        $this->source->switch('main');
        $this->connectTo('SM');

        self::assertSame('sm-1-round', array_column($this->commits()['items'], 'branch', 'sha')[$sha] ?? null);
    }

    #[TestDox('The old copy of a commit whose patch the default branch has is not listed, also next to a branch forked after the copy')]
    public function testCopyOnTheDefaultBranch(): void
    {
        $old = $this->source->switch('wip')->commit(['round.txt' => "once\n"], 'feat: SM-1 round once');
        $this->source->switch('main')->commit(['other.txt' => "other\n"], 'Other');
        $this->source->git('cherry-pick', $old);
        $copy = $this->source->revParse('HEAD');
        $this->source->switch('later')->commit(message: 'Later');
        $this->source->switch('main');
        $this->connectTo('SM');

        self::assertSame([[$copy, true]], array_map(static fn (array $item): array => [$item['sha'], $item['onDefaultBranch']], $this->commits()['items']));
    }

    #[TestDox('After an amend and a fetch, the list has the new commit and not the old one, which only a tag still has')]
    public function testAmend(): void
    {
        $old = $this->source->commit(message: 'feat: SM-1 first try');
        $this->connectTo('SM');
        $before = $this->commits();
        $this->source->git('commit', '--quiet', '--amend', '--allow-empty', '--message=feat: SM-1 second try');
        $this->source->git('tag', 'first-try', $old);
        $new = $this->source->revParse('HEAD');
        self::runQueuedMessages();

        $after = $this->commits();

        self::assertSame([[$old], [$new]], [array_column($before['items'], 'sha'), array_column($after['items'], 'sha')]);
    }

    #[TestDox('A repository of another project is not read')]
    public function testRepositoryOfAnotherProject(): void
    {
        $this->source->commit(message: 'feat: SM-1 in billing');
        $this->newSource('invoices')->commit(message: 'feat: SM-1 in invoices');
        self::storeProject($this->tenantId, 'DOC');
        $this->connectTo('SM');
        $this->connectTo('DOC', 'invoices');

        self::assertSame(['billing'], array_column($this->commits()['items'], 'repository'));
    }

    #[TestDox('A Pass-through repository and a repository whose clone is not ready are in unavailable with their reasons')]
    public function testUnavailableRepository(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $passThrough = Repository::gitHub($this->tenantId, RepositoryName::github('acme/billing'), 812345, 5551, true, Clock::get()->now(), CodeAccess::PassThrough);
        $entityManager->persist($passThrough);
        $entityManager->flush();
        $entityManager->clear();
        $this->connectTo('SM', 'billing', $passThrough->getId()->toString(), self::storeRepository($this->tenantId, 'archive')->getId()->toString());

        self::assertSame(['items' => [], 'unavailable' => [['repository' => 'acme/billing', 'reason' => 'no_server_copy'], ['repository' => 'archive', 'reason' => 'cloning']]], $this->commits());
    }

    #[TestDox('A clone that git cannot read is in unavailable with read_failed, and the other repositories are listed')]
    public function testReadFailure(): void
    {
        $this->newSource('invoices')->commit(message: 'feat: SM-1 in invoices');
        $broken = self::connectAndClone($this->client);
        $this->connectTo('SM', 'invoices', $broken);
        unlink(self::getContainer()->get(TenantDataPaths::class)->clonePath($this->tenantId, RepositoryId::fromString($broken)).'/HEAD');

        $commits = $this->commits();

        self::assertSame([['invoices'], [['repository' => 'billing', 'reason' => 'read_failed']]], [array_column($commits['items'], 'repository'), $commits['unavailable']]);
    }

    #[TestDox('A read queues one fetch of an idle local repository and lists the clone as it is')]
    public function testReadQueuesFetch(): void
    {
        $this->connectTo('SM');
        $this->source->commit(message: 'feat: SM-1 after the clone');

        $commits = $this->commits();

        self::assertSame([1, []], [self::queuedFetches(), $commits['items']]);
    }

    #[TestDox('After the queued fetch ran, a read 9 s after the first read queues none and a read at 10 s queues one')]
    #[DataProvider('fetchOutcomes')]
    public function testOneFetchRequestPer10Seconds(bool $fetchFails): void
    {
        $repositoryId = RepositoryId::fromString($this->connectTo('SM'));
        $this->commits();

        if ($fetchFails) {
            rename("{$this->localRepositoriesDirectory}/billing", "{$this->localRepositoriesDirectory}/moved");
        }

        self::runQueuedMessages();
        $failed = null !== self::freshRepository($repositoryId)->getLastError();
        Clock::get()->sleep(9);
        $this->commits();
        $after9Seconds = self::queuedFetches();
        Clock::get()->sleep(1);
        $this->commits();

        self::assertSame([$fetchFails, 0, 1], [$failed, $after9Seconds, self::queuedFetches()]);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function fetchOutcomes(): iterable
    {
        yield 'fetch succeeds' => [false];
        yield 'fetch fails' => [true];
    }

    #[TestDox('A repository with a running fetch gets no fetch request')]
    public function testRunningFetch(): void
    {
        $repositoryId = $this->connectTo('SM');
        self::getContainer()->get(RepositoryRepository::class)->startFetch(RepositoryId::fromString($repositoryId));

        $this->commits();

        self::assertSame(0, self::queuedFetches());
    }

    #[TestDox('A GitHub repository with Server copy in idle gets no fetch request, and its missing clone gives read_failed')]
    public function testGitHubRepository(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $gitHub = Repository::gitHub($this->tenantId, RepositoryName::github('acme/billing'), 812345, 5551, true, Clock::get()->now());
        $entityManager->persist($gitHub);
        $entityManager->flush();
        $entityManager->clear();
        self::getContainer()->get(RepositoryRepository::class)->recordSuccess($gitHub->getId(), CodeAccess::Clone, Clock::get()->now(), 'main', null);
        self::assertApiResponse(self::apiRequest($this->client, 'PATCH', '/api/v1/projects/SM', ['repositoryIds' => [$gitHub->getId()->toString()]]), 200, 'project.schema.json');

        $commits = $this->commits();

        self::assertSame([0, [['repository' => 'acme/billing', 'reason' => 'read_failed']]], [self::queuedFetches(), $commits['unavailable']]);
    }

    #[TestDox('A commit made after the last fetch is listed by the read after the queued fetch ran')]
    public function testCommitAfterTheLastFetch(): void
    {
        $this->connectTo('SM');
        $sha = $this->source->commit(message: 'feat: SM-1 after the clone');
        $this->commits();
        self::runQueuedMessages();

        self::assertSame([$sha], array_column($this->commits()['items'], 'sha'));
    }

    /**
     * Connects and clones the local source with R3, then sets it, with $otherRepositoryIds, as the repositories of the
     * project with P3.
     *
     * @return string The repository ID
     */
    private function connectTo(string $projectKey, string $directory = 'billing', string ...$otherRepositoryIds): string
    {
        $repositoryId = self::connectAndClone($this->client, $directory);
        self::assertApiResponse(self::apiRequest($this->client, 'PATCH', "/api/v1/projects/{$projectKey}", ['repositoryIds' => [$repositoryId, ...$otherRepositoryIds]]), 200, 'project.schema.json');

        return $repositoryId;
    }

    /**
     * The client restarts the kernel before each request, so the transport holds only what the last request queued.
     */
    private static function queuedFetches(): int
    {
        return \count(array_filter(self::asyncTransport()->getSent(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof FetchRepositoryJob));
    }

    /**
     * @return array<string, mixed>
     */
    private function commits(): array
    {
        return self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/issues/SM-1/commits'), 200, self::SCHEMA);
    }
}
