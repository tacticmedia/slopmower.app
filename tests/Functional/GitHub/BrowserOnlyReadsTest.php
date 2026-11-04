<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitHub;

use App\Application\CodeReaders;
use App\Application\ReviewCommands;
use App\Domain\Actor\Actor;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\ReviewId;
use App\Domain\Repository\CodeAccess;
use App\Entity\Repository;
use App\Entity\User;
use App\Git\ChangedFile;
use App\Repository\UserRepository;
use App\Tenancy\TenantContext;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\GitHub\ClonedGitHubRepositories;
use App\Tests\Support\GitHub\GitHubResponses;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\GitHubWebhooks;
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Messenger\QueuedMessages;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * D114 on a Browser only repository, read from the git-backed GitHub fake.
 */
final class BrowserOnlyReadsTest extends WebTestCase
{
    use ApiFixtures;
    use ClonedGitHubRepositories;
    use EnvironmentVariables;
    use GitHubRows;
    use GitHubWebhooks;
    use JsonApiAssertions;
    use LocalGitHub;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    private const string INVOICE = "<?php\n\nfinal class Invoice\n{\n    public function total(): int\n    {\n        return 3;\n    }\n}\n";

    private KernelBrowser $client;

    private GitRepositoryBuilder $source;

    protected function setUp(): void
    {
        $this->useLocalGitHub();
        $this->setEnvironmentVariable('APP_SCRATCH_DIR', $this->temporaryDirectory());
        $this->source = $this->gitHubSource();
        $this->client = static::createClient();
    }

    #[TestDox('R11 answers the token of the repository with contents and metadata read, answers code_on_server for Pass-through, and refuses a user without access')]
    public function testCodeToken(): void
    {
        $passThrough = self::clonedGitHubRepository(2, CodeAccess::PassThrough, 'hubot');
        $passThroughUser = self::userOf($passThrough);
        $repository = $this->repository(['src/Invoice.php' => self::INVOICE]);
        $repositoryUser = self::userOf($repository);

        $response = self::apiRequest($this->client, 'POST', "/api/v1/repositories/{$repository->getId()}/code-token");
        $token = self::assertApiResponse($response, 200, 'lists.schema.json#/$defs/CodeToken');
        $tokenRequests = self::getContainer()->get(GitHubResponses::class)->installationTokenRequests();
        $tokenRequest = end($tokenRequests);
        $this->client->loginUser($passThroughUser, 'web');
        $onServer = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/repositories/{$passThrough->getId()}/code-token"), 409, 'repository_unavailable');
        $this->client->loginUser($repositoryUser, 'web');
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE app_user SET login = ? WHERE tenant_id = ?', [GitHubResponses::FORMER_MEMBER_LOGIN, $repository->getTenantId()->toRfc4122()]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $noAccess = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/repositories/{$repository->getId()}/code-token"), 409, 'repository_unavailable');

        self::assertSame(['token' => GitHubResponses::INSTALLATION_TOKEN, 'expiresAt' => '2026-10-02T09:00:00Z', 'owner' => 'acme', 'name' => 'billing'], $token);
        self::assertSame(['repository_ids' => [812345], 'permissions' => ['contents' => 'read', 'metadata' => 'read']], $tokenRequest);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame(['code_on_server', 'clone_failed'], [$onServer['details']['reason'] ?? null, $noAccess['details']['reason'] ?? null]);
    }

    #[TestDox('R11 refuses while the installation is suspended')]
    public function testCodeTokenRefusesASuspendedInstallation(): void
    {
        $repository = $this->repository(['src/Invoice.php' => self::INVOICE]);
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE github_installation SET suspended_at = now() WHERE tenant_id = ?', [$repository->getTenantId()->toRfc4122()]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $error = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/repositories/{$repository->getId()}/code-token"), 409, 'repository_unavailable');

        self::assertSame('clone_failed', $error['details']['reason'] ?? null);
    }

    #[TestDox('R11 answers 429 past the code_token limit of the user')]
    public function testCodeTokenLimit(): void
    {
        $this->setEnvironmentVariable('APP_RATE_LIMIT_CODE_TOKEN_PER_HOUR', '1');
        $repository = $this->repository(['src/Invoice.php' => self::INVOICE]);
        $uri = "/api/v1/repositories/{$repository->getId()}/code-token";

        self::assertApiResponse(self::apiRequest($this->client, 'POST', $uri), 200, 'lists.schema.json#/$defs/CodeToken');

        self::assertApiError(self::apiRequest($this->client, 'POST', $uri), 429, 'rate_limited');
    }

    #[TestDox('The reader of Browser only refuses every read of file content without a call to GitHub')]
    public function testReaderRefusesContentReads(): void
    {
        $repository = $this->repository(['src/Invoice.php' => self::INVOICE]);
        $reader = self::getContainer()->get(CodeReaders::class)->of($repository);
        $commit = CommitSha::fromString($this->source->revParse('HEAD'));
        $blob = BlobSha::fromString($this->source->revParse('HEAD:src/Invoice.php'));
        $responses = self::getContainer()->get(GitHubResponses::class);
        $callsBefore = \count($responses->repositoryCalls());

        $reasons = array_map(static function (\Closure $read): string {
            try {
                $read();

                return 'read';
            } catch (RepositoryUnavailable $refused) {
                return $refused->details()['reason']->value;
            }
        }, [
            static fn () => $reader->readBlob($blob),
            static fn () => $reader->openBlob($blob),
            static fn () => $reader->unifiedZeroDiff($blob, $blob),
            static fn () => $reader->filesContaining($commit, 'total'),
            static fn () => $reader->withLocalCopy($commit, static fn (): null => null),
        ]);

        self::assertSame(array_fill(0, 5, 'code_in_browser'), $reasons);
        self::assertCount($callsBefore, $responses->repositoryCalls());
    }

    #[TestDox('A carry-over of a request whose file is deleted gives not_carried with file_deleted')]
    public function testCarryOverOfADeletedFile(): void
    {
        $repository = $this->repository(['src/Invoice.php' => self::INVOICE, 'src/Tax.php' => self::INVOICE."// Tax\n"]);
        $sourceReviewId = self::startedReview($repository);
        self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$sourceReviewId}/requests", [
            'anchor' => ['kind' => 'range', 'path' => 'src/Invoice.php', 'range' => ['startLine' => 5, 'endLine' => 8]],
            'body' => 'Check it.',
        ]), 201, 'request.schema.json');
        $this->source->git('rm', '--quiet', 'src/Invoice.php');
        $this->source->commit([]);
        $this->source->git('push', '--quiet', 'origin', 'main');
        $targetReviewId = (string) self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/repositories/{$repository->getId()}/reviews", ['ref' => 'main']), 201, 'review.schema.json')['id'];

        $result = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$targetReviewId}/carry-over", ['fromReviewId' => (string) $sourceReviewId]), 200, 'carry-over-result.schema.json');

        self::assertSame([['not_carried', 'orphaned', 'file_deleted']], array_map(static fn (array $item): array => [$item['outcome'], $item['location'], $item['reason']], $result['items']));
    }

    #[TestDox('A range request of Browser only locates with its range after a switch to Server copy')]
    public function testBrowserAnchorLocatesAfterASwitch(): void
    {
        $repository = $this->repository(['src/Invoice.php' => self::INVOICE]);
        $reviewId = self::startedReview($repository);
        $requestId = (string) self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$reviewId}/requests", [
            'anchor' => ['kind' => 'range', 'path' => 'src/Invoice.php', 'range' => ['startLine' => 5, 'endLine' => 8]],
            'body' => 'Check it.',
        ]), 201, 'request.schema.json')['id'];
        self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/repositories/{$repository->getId()}/code-access", ['codeAccess' => 'clone']), 200, 'repository.schema.json');
        self::runQueuedMessages();
        $this->source->commit(['src/Invoice.php' => "<?php\n\n// One\n// Two\n".substr(self::INVOICE, \strlen("<?php\n\n"))]);
        $this->source->git('push', '--quiet', 'origin', 'main');
        self::assertSame(202, self::deliverFixture($this->client, 'push')->getStatusCode());
        self::runQueuedMessages();

        $request = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/requests/{$requestId}"), 200, 'request.schema.json');

        self::assertSame(['shifted', ['startLine' => 7, 'endLine' => 10]], [$request['location']['status'] ?? null, $request['location']['range'] ?? null]);
        self::assertSame('    public function total(): int', $request['anchor']['snapshot']['lines'][0] ?? null);
    }

    #[TestDox('V8, R7, V9, and V14 answer code_in_browser, and V7 leaves out what needs the blob')]
    public function testCodeReadsAnswerCodeInBrowser(): void
    {
        $repository = $this->repository(['src/Invoice.php' => self::INVOICE]);
        $review = '/api/v1/reviews/'.self::startedReview($repository);
        $blobSha = $this->source->revParse('HEAD:src/Invoice.php');

        $reasons = array_map(
            fn (string $uri): mixed => self::assertApiError(self::apiRequest($this->client, 'GET', $uri), 409, 'repository_unavailable')['details']['reason'] ?? null,
            ["{$review}/raw?path=src%2FInvoice.php", "/api/v1/repositories/{$repository->getId()}/blobs/{$blobSha}", "{$review}/symbols?path=src%2FInvoice.php", "{$review}/declarations?path=src%2FInvoice.php&name=Invoice"],
        );
        $file = self::assertApiResponse(self::apiRequest($this->client, 'GET', "{$review}/file?path=src%2FInvoice.php"), 200, 'file-content.schema.json');

        self::assertSame(array_fill(0, 4, 'code_in_browser'), $reasons);
        self::assertSame([null, null, null, null, $blobSha], [$file['renderable'], $file['lineCount'], $file['binary'], $file['symlinkTarget'], $file['blobSha']]);
    }

    #[TestDox('Requests locate by blob SHA: an unchanged file is exact, its blob at a new path shifted, and a changed file unverified and outdated')]
    public function testMetadataLocate(): void
    {
        $repository = $this->repository(['src/Same.php' => self::INVOICE, 'src/Moved.php' => self::INVOICE."// Moved\n", 'src/Changed.php' => self::INVOICE."// Changed\n"]);
        $review = '/api/v1/reviews/'.self::startedReview($repository);
        $requestIds = array_map(fn (string $path): string => (string) self::assertApiResponse(self::apiRequest($this->client, 'POST', "{$review}/requests", [
            'anchor' => ['kind' => 'range', 'path' => $path, 'range' => ['startLine' => 5, 'endLine' => 8]],
            'body' => 'Check it.',
        ]), 201, 'request.schema.json')['id'], ['src/Same.php', 'src/Moved.php', 'src/Changed.php']);
        $this->source->git('rm', '--quiet', 'src/Moved.php');
        $this->source->commit(['lib/Moved.php' => self::INVOICE."// Moved\n", 'src/Changed.php' => self::INVOICE."// Changed again\n"]);
        $this->source->git('push', '--quiet', 'origin', 'main');

        self::assertSame(202, self::deliverFixture($this->client, 'push')->getStatusCode());
        self::runQueuedMessages();
        $located = array_map(function (string $id): array {
            $request = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/requests/{$id}"), 200, 'request.schema.json');

            return [$request['location']['status'] ?? null, $request['location']['path'] ?? null, $request['location']['range'] ?? null, $request['outdated']];
        }, $requestIds);

        $range = ['startLine' => 5, 'endLine' => 8];
        self::assertSame([['exact', 'src/Same.php', $range, false], ['shifted', 'lib/Moved.php', $range, false], ['unverified', 'src/Changed.php', $range, true]], $located);
    }

    #[TestDox('Q9 reads only the trees on the path of a deep change, and gives a rename for an equal blob SHA')]
    public function testCommitDiffWalksDifferingTrees(): void
    {
        $repository = $this->repository(['deep/a/b/file.txt' => "One\n", 'other/x.txt' => "Ex\n"]);
        $parent = CommitSha::fromString($this->source->revParse('HEAD'));
        $deep = CommitSha::fromString($this->source->commit(['deep/a/b/file.txt' => "Two\n"]));
        $this->source->git('rm', '--quiet', 'other/x.txt');
        $renamed = CommitSha::fromString($this->source->commit(['moved/x.txt' => "Ex\n"]));
        $this->source->git('push', '--quiet', 'origin', 'main');
        $reader = self::getContainer()->get(CodeReaders::class)->of($repository);
        $responses = self::getContainer()->get(GitHubResponses::class);
        $callsBefore = \count($responses->repositoryCalls());

        $deepChanges = $reader->changedFiles($parent, $deep);
        $treeCalls = array_values(array_filter(\array_slice($responses->repositoryCalls(), $callsBefore), static fn (string $call): bool => str_contains($call, '/git/trees/')));
        $renameChanges = $reader->changedFiles($deep, $renamed);

        $onPath = array_merge(...array_map(static fn (CommitSha $commit): array => array_map(static fn (string $directory): string => "acme/billing/git/trees/{$commit}{$directory}", ['', '%3Adeep', '%3Adeep/a', '%3Adeep/a/b']), [$parent, $deep]));
        self::assertNotSame([], $treeCalls);
        self::assertSame([], array_values(array_diff($treeCalls, $onPath)), 'Only the trees on the changed path are read.');
        self::assertSame([['modified', 'deep/a/b/file.txt', null, null]], self::changes($deepChanges));
        self::assertSame([['renamed', 'moved/x.txt', 'other/x.txt', null]], self::changes($renameChanges));
    }

    #[TestDox('A symbol anchor answers 422 on a Browser only repository')]
    public function testSymbolAnchorIsRefused(): void
    {
        $repository = $this->repository(['src/Invoice.php' => self::INVOICE]);
        $review = '/api/v1/reviews/'.self::startedReview($repository);

        $error = self::assertApiError(self::apiRequest($this->client, 'POST', "{$review}/requests", [
            'anchor' => ['kind' => 'symbol', 'path' => 'src/Invoice.php', 'symbol' => ['fqn' => 'Invoice']],
            'body' => 'Rename it.',
        ]), 422, 'invalid_input');

        self::assertSame(['anchor.kind'], array_column($error['details']['violations'] ?? [], 'field'));
    }

    /**
     * A connected and synced Browser only repository with the files pushed after the first commit, and its user
     * signed in.
     *
     * @param array<string, string> $files
     */
    private function repository(array $files): Repository
    {
        $this->source->commit($files);
        $this->source->git('push', '--quiet', 'origin', 'main');
        $repository = self::clonedGitHubRepository(1, CodeAccess::Browser, 'octocat');
        self::getContainer()->get(TenantContext::class)->set($repository->getTenantId());
        $this->client->loginUser(self::userOf($repository), 'web');

        return $repository;
    }

    private static function startedReview(Repository $repository): ReviewId
    {
        $reviewId = self::getContainer()->get(ReviewCommands::class)->start($repository->getId(), null, null, Actor::user(self::userOf($repository)->getId()));
        self::runQueuedMessages();

        return $reviewId;
    }

    private static function userOf(Repository $repository): User
    {
        return self::getContainer()->get(UserRepository::class)->findOneBy(['tenantId' => $repository->getTenantId()]) ?? self::fail('The tenant has a user.');
    }

    /**
     * @param list<ChangedFile> $changes
     *
     * @return list<array{string, string, string|null, bool|null}>
     */
    private static function changes(array $changes): array
    {
        return array_map(static fn (ChangedFile $change): array => [$change->status->value, $change->path, $change->oldPath, $change->isBinary()], $changes);
    }
}
