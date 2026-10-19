<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;

/**
 * R9 of docs/specs/rest-api.md.
 */
final class CommitListTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const string ADA = 'Ada Lovelace <ada@example.invalid>';

    private const string SCHEMA = 'lists.schema.json#/$defs/CommitLog';

    private KernelBrowser $client;

    private User $user;

    private GitRepositoryBuilder $source;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
    }

    #[TestDox('Without a ref, the list holds the latest 10 commits of the default branch, newest first')]
    public function testDefaultBranch(): void
    {
        foreach (range(1, 10) as $number) {
            $this->source->commit(['counter.txt' => "{$number}\n"], "Commit {$number}");
        }
        $head = $this->source->commit(['counter.txt' => "11\n"], "Say whole cents\n\nThe body is not the subject.", self::ADA, '2026-05-06T07:08:09Z');
        $repositoryId = self::connectAndClone($this->client);
        $repository = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$repositoryId}"), 200, 'repository.schema.json');

        $log = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$repositoryId}/commits"), 200, self::SCHEMA);

        self::assertSame([$repositoryId, 'main'], [$log['repositoryId'], $log['ref']]);
        self::assertSame(['Say whole cents', ...array_map(static fn (int $number): string => "Commit {$number}", range(10, 2))], array_column($log['items'], 'subject'));
        self::assertSame($repository['sync']['defaultBranchHead'], $log['items'][0]['sha']);
        self::assertSame([
            'sha' => $head,
            'subject' => 'Say whole cents',
            'authorName' => 'Ada Lovelace',
            'authoredAt' => '2026-05-06T07:08:09Z',
            'committedAt' => gmdate('Y-m-d\TH:i:s\Z', (int) trim($this->source->git('log', '-1', '--format=%ct', $head))),
        ], $log['items'][0]);
    }

    #[TestDox('A branch, a tag, and a SHA each list from their commit, and the ref is returned as given')]
    public function testBranchTagAndSha(): void
    {
        $tagged = $this->source->commit(message: 'Tagged');
        $this->source->git('tag', '--annotate', '--message=Release', 'v1.0');
        $feature = $this->source->switch('feature')->commit(message: 'On feature');
        $this->source->switch('main')->commit(message: 'On main');
        $repositoryId = self::connectAndClone($this->client);

        foreach (['feature' => $feature, 'v1.0' => $tagged, $tagged => $tagged] as $ref => $expected) {
            $log = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$repositoryId}/commits?limit=1&ref=".rawurlencode((string) $ref)), 200, self::SCHEMA);
            self::assertSame([(string) $ref, [$expected]], [$log['ref'], array_column($log['items'], 'sha')], (string) $ref);
        }
    }

    #[TestDox('A limit of 51 gives 422, and a limit that is no integer gives 400')]
    public function testLimit(): void
    {
        $repositoryId = self::connectAndClone($this->client);

        foreach (['51' => Response::HTTP_UNPROCESSABLE_ENTITY, 'x' => Response::HTTP_BAD_REQUEST] as $limit => $status) {
            $error = self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$repositoryId}/commits?limit={$limit}"), $status, 'invalid_input');
            self::assertSame(['limit'], array_column($error['details']['violations'] ?? [], 'field'), (string) $limit);
        }
    }

    #[TestDox('An unknown ref gives 422 commit_not_found')]
    public function testUnknownRef(): void
    {
        $repositoryId = self::connectAndClone($this->client);

        $error = self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$repositoryId}/commits?ref=no-such-branch"), 422, 'commit_not_found');

        self::assertSame(['commit' => 'no-such-branch'], $error['details']);
    }

    #[TestDox('An option-like ref and a range give 422 invalid_input on ref')]
    public function testMalformedRef(): void
    {
        $repositoryId = self::connectAndClone($this->client);

        foreach (['-x', 'a..b'] as $ref) {
            $error = self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$repositoryId}/commits?ref={$ref}"), 422, 'invalid_input');
            self::assertSame(['ref'], array_column($error['details']['violations'] ?? [], 'field'), $ref);
        }
    }

    #[TestDox('A cloning repository and a failed clone give 409 repository_unavailable with their reasons')]
    public function testCloneNotReady(): void
    {
        $broken = $this->newSource('invoices');
        // An empty .git directory is still a candidate, and its clone fails.
        new Filesystem()->remove("{$broken->path}/.git");
        new Filesystem()->mkdir("{$broken->path}/.git");
        $failed = self::connectAndClone($this->client, 'invoices');
        $cloning = self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/repositories', ['source' => 'local', 'directory' => 'billing']), 201, 'repository.schema.json');

        $errors = array_map(
            fn (string $repositoryId): array => self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$repositoryId}/commits?ref=main"), 409, 'repository_unavailable'),
            [(string) $cloning['id'], $failed],
        );

        self::assertSame(['cloning', 'clone_failed'], array_map(static fn (array $error): mixed => $error['details']['reason'] ?? null, $errors));
    }

    #[TestDox('Without a ref, a repository without a default branch gives 422 on ref before the clone state')]
    public function testNoRefWithoutDefaultBranch(): void
    {
        $repository = self::assertApiResponse(self::apiRequest($this->client, 'POST', '/api/v1/repositories', ['source' => 'local', 'directory' => 'billing']), 201, 'repository.schema.json');

        $error = self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$repository['id']}/commits"), 422, 'invalid_input');

        self::assertSame(['ref'], array_column($error['details']['violations'] ?? [], 'field'));
    }

    #[TestDox('A commit made after the clone shows only after R5 fetched it')]
    public function testNoFetch(): void
    {
        $repositoryId = self::connectAndClone($this->client);
        $later = $this->source->commit(['later.txt' => "Later\n"], 'Later');
        $commits = "/api/v1/repositories/{$repositoryId}/commits";

        $before = self::assertApiResponse(self::apiRequest($this->client, 'GET', $commits), 200, self::SCHEMA);
        self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/repositories/{$repositoryId}/fetch"), 202, 'repository.schema.json');
        self::runQueuedMessages();
        $after = self::assertApiResponse(self::apiRequest($this->client, 'GET', $commits), 200, self::SCHEMA);

        self::assertNotContains($later, array_column($before['items'], 'sha'));
        self::assertSame($later, $after['items'][0]['sha'] ?? null);
    }

    #[TestDox('A .mailmap in the repository does not change the author name')]
    public function testNoMailmap(): void
    {
        $this->source->commit(['.mailmap' => "Augusta King <augusta@example.invalid> Ada Lovelace <ada@example.invalid>\n"], 'Map Ada', self::ADA);
        $repositoryId = self::connectAndClone($this->client);

        $log = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$repositoryId}/commits?limit=1"), 200, self::SCHEMA);

        self::assertSame('Ada Lovelace', $log['items'][0]['authorName'] ?? null);
    }

    #[TestDox('A token scoped to another repository gets 404 not_found')]
    public function testTokenScopedToAnotherRepository(): void
    {
        $repositoryId = self::connectAndClone($this->client);
        [, $secret] = self::storeToken($this->user, self::storeRepository($this->user->getTenantId(), 'alpha'));

        self::assertApiError(self::bearerRequest($this->client, $secret, 'GET', "/api/v1/repositories/{$repositoryId}/commits"), 404, 'not_found');
    }
}
