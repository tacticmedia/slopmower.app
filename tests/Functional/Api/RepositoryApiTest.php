<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Message\CloneRepositoryJob;
use App\Message\FetchRepositoryJob;
use App\Source\LocalRepositoryDiscovery;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;

final class RepositoryApiTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->client = static::createClient();
        self::signIn($this->client);
    }

    #[TestDox('Lists the local candidates')]
    public function testListsLocalCandidates(): void
    {
        $this->newSource('billing');
        $this->newSource('shipping');
        $mirror = $this->newSource('mirror');
        $mirror->git('clone', '--quiet', '--bare', $mirror->path, "{$this->localRepositoriesDirectory}/mirror.git");
        $billingId = $this->connectThroughApi('billing')['id'];

        $all = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/repositories/candidates'), 200, 'lists.schema.json#/$defs/CandidateList');
        $filtered = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/repositories/candidates?q=I&includeConnected=0'), 200, 'lists.schema.json#/$defs/CandidateList');

        self::assertSame(
            [
                ['source' => 'local', 'name' => 'billing', 'connectedRepositoryId' => $billingId, 'github' => null, 'local' => ['directory' => 'billing', 'bare' => false, 'headBranch' => 'main']],
                ['source' => 'local', 'name' => 'mirror', 'connectedRepositoryId' => null, 'github' => null, 'local' => ['directory' => 'mirror', 'bare' => false, 'headBranch' => 'main']],
                ['source' => 'local', 'name' => 'mirror.git', 'connectedRepositoryId' => null, 'github' => null, 'local' => ['directory' => 'mirror.git', 'bare' => true, 'headBranch' => 'main']],
                ['source' => 'local', 'name' => 'shipping', 'connectedRepositoryId' => null, 'github' => null, 'local' => ['directory' => 'shipping', 'bare' => false, 'headBranch' => 'main']],
            ],
            $all['items'],
        );
        self::assertSame(['truncated' => false, 'localEnabled' => true, 'githubListedAt' => null, 'githubConfigureUrl' => null], array_diff_key($all, ['items' => true]));
        self::assertSame(['mirror', 'mirror.git', 'shipping'], array_column($filtered['items'], 'name'));
    }

    #[TestDox('The candidate filters apply before the 1,000-item limit')]
    public function testFiltersApplyBeforeTheLimit(): void
    {
        $filesystem = new Filesystem();

        foreach ([...range(0, LocalRepositoryDiscovery::MAX_CANDIDATES), 'target'] as $name) {
            $bare = is_int($name) ? sprintf('%s/repository-%04d.git', $this->localRepositoriesDirectory, $name) : "{$this->localRepositoriesDirectory}/zz-{$name}.git";
            $filesystem->mkdir(["{$bare}/objects", "{$bare}/refs"]);
            $filesystem->dumpFile("{$bare}/HEAD", "ref: refs/heads/main\n");
        }

        $all = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/repositories/candidates'), 200, 'lists.schema.json#/$defs/CandidateList');
        $filtered = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/repositories/candidates?q=TARGET'), 200, 'lists.schema.json#/$defs/CandidateList');

        self::assertTrue($all['truncated']);
        self::assertSame(['zz-target.git'], array_column($filtered['items'], 'name'));
        self::assertFalse($filtered['truncated']);
    }

    #[TestDox('Connecting a candidate returns 201 with a Location header')]
    public function testConnectReturnsCreated(): void
    {
        $this->newSource('billing');

        $response = self::apiRequest($this->client, 'POST', '/api/v1/repositories', ['source' => 'local', 'directory' => 'billing']);

        $repository = self::assertApiResponse($response, 201, 'repository.schema.json');
        self::assertSame("/api/v1/repositories/{$repository['id']}", $response->headers->get('Location'));
        self::assertSame(['source' => 'local', 'name' => 'billing', 'github' => null, 'local' => ['directory' => 'billing']], array_intersect_key($repository, array_flip(['source', 'name', 'github', 'local'])));
        self::assertSame(['state' => 'cloning', 'lastFetchedAt' => null, 'lastError' => null, 'defaultBranchHead' => null], $repository['sync']);
        self::assertSame([CloneRepositoryJob::class], self::sentMessageClasses());
    }

    #[TestDox('Connecting it again returns 200 with the same repository')]
    public function testConnectAgainReturnsExisting(): void
    {
        $this->newSource('billing');
        $first = $this->connectThroughApi('billing');

        $response = self::apiRequest($this->client, 'POST', '/api/v1/repositories', ['source' => 'local', 'directory' => 'billing']);

        self::assertSame($first['id'], self::assertApiResponse($response, 200, 'repository.schema.json')['id']);
        self::assertFalse($response->headers->has('Location'));
    }

    #[TestDox('Lists repositories and finds one by name')]
    public function testListsAndFindsByName(): void
    {
        $this->newSource('billing');
        $this->newSource('Zebra');
        $billingId = $this->connectThroughApi('billing')['id'];
        $zebraId = $this->connectThroughApi('Zebra')['id'];

        $all = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/repositories'), 200, 'lists.schema.json#/$defs/RepositoryPage');
        $byName = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/repositories?name=billing'), 200, 'lists.schema.json#/$defs/RepositoryPage');
        $byId = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/repositories?name='.strtolower((string) $zebraId)), 200, 'lists.schema.json#/$defs/RepositoryPage');
        $byWrongCase = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/repositories?name=BILLING'), 200, 'lists.schema.json#/$defs/RepositoryPage');

        self::assertSame([$billingId, $zebraId], array_column($all['items'], 'id'), 'Sorted by name case-insensitively.');
        self::assertNull($all['nextCursor']);
        self::assertSame([$billingId], array_column($byName['items'], 'id'));
        self::assertSame([$zebraId], array_column($byId['items'], 'id'));
        self::assertSame([], $byWrongCase['items'], 'A local name matches exactly.');
    }

    #[TestDox('Gets one repository')]
    public function testGetsOneRepository(): void
    {
        $this->newSource('billing');
        $id = (string) $this->connectThroughApi('billing')['id'];
        self::runQueuedMessages();

        $repository = self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/repositories/'.strtolower($id)), 200, 'repository.schema.json');

        self::assertSame($id, $repository['id']);
        self::assertSame('main', $repository['defaultBranch']);
        self::assertSame('active', $repository['access']);
        self::assertSame(0, $repository['openReviewCount']);
        self::assertIsArray($repository['sync']);
        self::assertSame('idle', $repository['sync']['state']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $repository['sync']['lastFetchedAt']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $repository['sync']['defaultBranchHead']);
    }

    #[TestDox('Fetch returns 202 with the sync state fetching')]
    public function testFetchReturnsAccepted(): void
    {
        $this->newSource('billing');
        $id = $this->connectThroughApi('billing')['id'];
        self::runQueuedMessages();

        $repository = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/repositories/{$id}/fetch"), 202, 'repository.schema.json');

        self::assertIsArray($repository['sync']);
        self::assertSame('fetching', $repository['sync']['state']);
        self::assertSame([FetchRepositoryJob::class], self::sentMessageClasses());
    }

    /**
     * @return array<string, mixed>
     */
    private function connectThroughApi(string $directory): array
    {
        return self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/repositories', ['source' => 'local', 'directory' => $directory]), 201, 'repository.schema.json');
    }

    /**
     * @return list<class-string>
     */
    private static function sentMessageClasses(): array
    {
        return array_values(array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, self::asyncTransport()->getSent()));
    }
}
