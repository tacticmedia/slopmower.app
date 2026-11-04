<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitHub;

use App\Anchoring\Locator;
use App\Application\FetchRepository;
use App\Application\ReviewCommands;
use App\Domain\Actor\Actor;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Anchor\LineRange;
use App\Domain\Anchor\SnapshotCapture;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\EventId;
use App\Domain\Identity\ReviewId;
use App\Domain\Location\LocationStatus;
use App\Domain\Repository\CodeAccess;
use App\Domain\Repository\SyncState;
use App\Entity\Repository;
use App\Entity\User;
use App\Messenger\TenantStamp;
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
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Mcp\McpTestClient;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Messenger\ScratchProbe;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;

/**
 * D112 and D113 on a Pass-through repository, read from the git-backed GitHub fake.
 */
final class PassThroughReadsTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use ClonedGitHubRepositories;
    use EnvironmentVariables;
    use GitHubRows;
    use JsonApiAssertions;
    use LocalGitHub;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    private const string TAX = "<?php\n\nfinal class Tax\n{\n    public function rate(): float\n    {\n        \$base = 0.19;\n        \$reduced = 0.07;\n\n        return \$base - \$reduced;\n    }\n}\n";

    private string $scratch;

    private GitRepositoryBuilder $source;

    protected function setUp(): void
    {
        $this->useLocalGitHub();
        $this->scratch = $this->temporaryDirectory();
        $this->setEnvironmentVariable('APP_SCRATCH_DIR', $this->scratch);
        $this->source = $this->gitHubSource();
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function blobThresholds(): iterable
    {
        yield 'up to the threshold' => ['10', 3, 0];
        yield 'above the threshold' => ['2', 0, 1];
    }

    #[TestDox('The index reads unparsed blobs from GitHub up to the threshold, else from one scratch copy, and walks a truncated tree')]
    #[DataProvider('blobThresholds')]
    public function testIndexReadsBlobsOrOneScratchCopy(string $threshold, int $blobReads, int $scratchCopies): void
    {
        $this->setEnvironmentVariable('APP_INDEX_MAX_BLOB_READS', $threshold);
        $repository = $this->passThroughRepository(['src/A.php' => "<?php\nfinal class A {}\n", 'src/B.php' => "<?php\nfinal class B {}\n", 'lib/C.php' => "<?php\nfinal class C {}\n", 'lib/notes.txt' => "Notes\n"]);
        self::startedReview($repository);
        $responses = self::getContainer()->get(GitHubResponses::class);
        $responses->gitBacked('acme/billing')->truncateNextRecursiveTree();
        $callsBefore = \count($responses->repositoryCalls());

        self::runQueuedMessages();

        $calls = \array_slice($responses->repositoryCalls(), $callsBefore);
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(['README.md', 'lib/C.php', 'lib/notes.txt', 'src/A.php', 'src/B.php'], $connection->fetchFirstColumn('SELECT path FROM indexed_file WHERE repository_id = ? ORDER BY path', [$repository->getId()->toRfc4122()]));
        self::assertSame(3, (int) $connection->fetchOne('SELECT count(*) FROM indexed_blob WHERE repository_id = ?', [$repository->getId()->toRfc4122()]));
        self::assertSame(
            [$blobReads, $scratchCopies],
            [\count(array_filter($calls, static fn (string $call): bool => str_starts_with($call, 'acme/billing/git/blobs/'))), \count(array_keys($calls, 'acme/billing', true))],
            'A scratch copy reads the GitHub size of the repository once.',
        );
    }

    #[TestDox('F9: a range request whose code moved to another file at the target is shifted there')]
    public function testRangeRequestShiftsToAnotherFile(): void
    {
        $repository = $this->passThroughRepository(['src/Tax.php' => self::TAX]);
        $from = CommitSha::fromString($this->source->revParse('HEAD'));
        $range = LineRange::of(7, 10);
        $anchor = Anchor::of(AnchorKind::Range, RepoPath::fromString('src/Tax.php'), $from, BlobSha::fromString($this->source->revParse('HEAD:src/Tax.php')), $range, null, SnapshotCapture::capture(self::TAX, $range)->withoutText());
        $this->source->git('rm', '--quiet', 'src/Tax.php');
        $rates = "<?php\n\n".implode('', array_map(static fn (int $n): string => "// Rate table line {$n}\n", range(1, 20)))."final class Rates\n{\n    public function rate(): float\n    {\n        \$base = 0.19;\n        \$reduced = 0.07;\n\n        return \$base - \$reduced;\n    }\n}\n";
        $to = CommitSha::fromString($this->source->commit(['src/Rates.php' => $rates]));
        $this->source->git('push', '--quiet', 'origin', 'main');

        $location = self::getContainer()->get(Locator::class)->locate($repository, EventId::generate(), $anchor, $to);

        self::assertSame([LocationStatus::Shifted, 'src/Rates.php', [27, 30]], [$location->status, $location->path?->toString(), null === $location->range ? null : [$location->range->startLine, $location->range->endLine]]);
    }

    #[TestDox('A message that fails after a scratch copy leaves no scratch directory, and two greps of one commit fetch once')]
    public function testFailedMessageLeavesNoScratchCopy(): void
    {
        $repository = $this->passThroughRepository([]);
        $responses = self::getContainer()->get(GitHubResponses::class);
        $callsBefore = \count($responses->repositoryCalls());
        self::asyncTransport()->send(new Envelope(new ScratchProbe($repository->getId(), CommitSha::fromString($this->source->revParse('HEAD'))), [new TenantStamp($repository->getTenantId())]));

        $application = new Application(self::$kernel ?? self::fail('The kernel is booted.'));
        $application->setAutoExit(false);
        new CommandTester($application->find('messenger:consume'))->execute(['receivers' => ['async'], '--limit' => '1', '--time-limit' => '10']);

        self::assertCount(1, array_keys(\array_slice($responses->repositoryCalls(), $callsBefore), 'acme/billing', true));
        self::assertSame([], self::scratchEntries($this->scratch));
    }

    #[TestDox('A head sync whose D88 check answers none sets failed, and a file read then answers 409')]
    public function testFailedAccessCheckSetsFailed(): void
    {
        $client = static::createClient();
        $repository = $this->passThroughRepository(['src/Tax.php' => self::TAX]);
        $reviewId = self::startedReview($repository);
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE app_user SET login = ? WHERE tenant_id = ?', [GitHubResponses::FORMER_MEMBER_LOGIN, $repository->getTenantId()->toRfc4122()]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::getContainer()->get(FetchRepository::class)->request($repository->getId());
        self::runQueuedMessages();

        self::assertSame(SyncState::Failed, self::storedRepository($repository)->getSyncState());
        $client->loginUser(self::userOf($repository), 'web');
        self::assertApiError(self::apiRequest($client, 'GET', "/api/v1/reviews/{$reviewId}/file?path=src%2FTax.php"), 409, 'repository_unavailable');
    }

    #[TestDox('GitHub answering 403 with no requests left gives 429 rate_limited with Retry-After')]
    public function testRateLimitGivesTooManyRequests(): void
    {
        $clock = self::mockTime('2026-11-04 10:00:00');
        $client = static::createClient();
        $repository = $this->passThroughRepository([]);
        $reviewId = self::startedReview($repository);
        $client->loginUser(self::userOf($repository), 'web');
        self::getContainer()->get(GitHubResponses::class)->answerCodeRead('git/trees/', 403, [
            'x-ratelimit-limit' => '5000',
            'x-ratelimit-remaining' => '0',
            'x-ratelimit-reset' => (string) ($clock->now()->getTimestamp() + 120),
        ]);

        $response = self::apiRequest($client, 'GET', "/api/v1/reviews/{$reviewId}/tree");

        self::assertSame(['retryAfterSeconds' => 120, 'limit' => 5000], self::assertApiError($response, 429, 'rate_limited')['details']);
        self::assertSame('120', $response->headers->get('Retry-After'));
    }

    #[TestDox('R7 streams the whole blob when GitHub sends no Content-Length, and leaves the header out')]
    public function testBlobWithoutContentLengthStreamsWhole(): void
    {
        $text = str_repeat("A line of the ledger.\n", 1000);
        $client = static::createClient();
        $repository = $this->passThroughRepository(['ledger.txt' => $text]);
        $client->loginUser(self::userOf($repository), 'web');

        $client->request('GET', "/api/v1/repositories/{$repository->getId()}/blobs/{$this->source->revParse('HEAD:ledger.txt')}");

        self::assertSame([200, \strlen($text), null], [$client->getResponse()->getStatusCode(), \strlen($client->getInternalResponse()->getContent()), $client->getResponse()->headers->get('Content-Length')]);
        self::assertSame($text, $client->getInternalResponse()->getContent());
    }

    #[TestDox('A GraphQL blame answer with errors gives 409 repository_unavailable')]
    public function testBlameWithErrorsGivesRepositoryUnavailable(): void
    {
        $client = static::createClient();
        $repository = $this->passThroughRepository(['src/Tax.php' => self::TAX]);
        $reviewId = self::startedReview($repository);
        $client->loginUser(self::userOf($repository), 'web');
        self::getContainer()->get(GitHubResponses::class)->answerCodeRead('graphql', 200, ['content-type' => 'application/json'], '{"data":{"repository":{"object":{"blame":null}}},"errors":[{"type":"SOMETHING_WENT_WRONG","message":"Something went wrong."}]}');

        self::assertApiError(self::apiRequest($client, 'GET', "/api/v1/reviews/{$reviewId}/blame?path=src%2FTax.php"), 409, 'repository_unavailable');
    }

    #[TestDox('After a D88 check answers none, get_request returns no snapshot text')]
    public function testGetRequestGivesNoTextAfterAFailedAccessCheck(): void
    {
        $client = static::createClient();
        $repository = $this->passThroughRepository(['src/Tax.php' => self::TAX]);
        $reviewId = self::startedReview($repository);
        $user = self::userOf($repository);
        $client->loginUser($user, 'web');
        $requestId = (string) self::assertApiResponse(self::apiRequest($client, 'POST', "/api/v1/reviews/{$reviewId}/requests", [
            'anchor' => ['kind' => 'range', 'path' => 'src/Tax.php', 'range' => ['startLine' => 7, 'endLine' => 8]],
            'body' => 'Name the rates.',
        ]), 201, 'request.schema.json')['id'];
        [, $secret] = self::storeToken($user);
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE app_user SET login = ? WHERE tenant_id = ?', [GitHubResponses::FORMER_MEMBER_LOGIN, $repository->getTenantId()->toRfc4122()]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::getContainer()->get(FetchRepository::class)->request($repository->getId());
        self::runQueuedMessages();
        $mcp = new McpTestClient($client, $secret);
        $mcp->initialize();

        $snapshot = $mcp->callTool('get_request', ['id' => $requestId])['structuredContent']['anchor']['snapshot'] ?? null;

        self::assertSame(SyncState::Failed, self::storedRepository($repository)->getSyncState());
        self::assertEquals(['lines' => null, 'before' => null, 'after' => null], array_intersect_key((array) $snapshot, array_flip(['lines', 'before', 'after'])));
    }

    #[TestDox('get_request returns the snapshot text rebuilt from the blob, and null text when GitHub no longer serves the blob')]
    public function testGetRequestRebuildsSnapshotText(): void
    {
        $client = static::createClient();
        $repository = $this->passThroughRepository(['src/Tax.php' => self::TAX]);
        $reviewId = self::startedReview($repository);
        $user = self::userOf($repository);
        $client->loginUser($user, 'web');
        $requestId = (string) self::assertApiResponse(self::apiRequest($client, 'POST', "/api/v1/reviews/{$reviewId}/requests", [
            'anchor' => ['kind' => 'range', 'path' => 'src/Tax.php', 'range' => ['startLine' => 7, 'endLine' => 8]],
            'body' => 'Name the rates.',
        ]), 201, 'request.schema.json')['id'];
        [, $secret] = self::storeToken($user);
        // The fake keeps its answers only in one container.
        $client->disableReboot();
        $mcp = new McpTestClient($client, $secret);
        $mcp->initialize();

        $rebuilt = $mcp->callTool('get_request', ['id' => $requestId])['structuredContent']['anchor']['snapshot'] ?? null;
        self::getContainer()->get(GitHubResponses::class)->answerCodeRead('git/blobs/'.$this->source->revParse('HEAD:src/Tax.php'), 404);
        $gone = $mcp->callTool('get_request', ['id' => $requestId])['structuredContent']['anchor']['snapshot'] ?? null;

        self::assertSame([['        $base = 0.19;', '        $reduced = 0.07;'], ['{', '    public function rate(): float', '    {'], ['', '        return $base - $reduced;', '    }']], [$rebuilt['lines'] ?? null, $rebuilt['before'] ?? null, $rebuilt['after'] ?? null]);
        self::assertEquals(['lines' => null, 'before' => null, 'after' => null, 'lineCount' => 2], array_intersect_key((array) $gone, array_flip(['lines', 'before', 'after', 'lineCount'])));
    }

    /**
     * @param array<string, string> $files Committed and pushed after the first commit of acme/billing
     */
    private function passThroughRepository(array $files): Repository
    {
        if ([] !== $files) {
            $this->source->commit($files);
            $this->source->git('push', '--quiet', 'origin', 'main');
        }

        $repository = self::clonedGitHubRepository(1, CodeAccess::PassThrough, 'octocat');
        self::getContainer()->get(TenantContext::class)->set($repository->getTenantId());

        return $repository;
    }

    /**
     * V1 at the default branch, without running the index job.
     */
    private static function startedReview(Repository $repository): ReviewId
    {
        return self::getContainer()->get(ReviewCommands::class)->start($repository->getId(), null, null, Actor::user(self::userOf($repository)->getId()));
    }

    private static function userOf(Repository $repository): User
    {
        return self::getContainer()->get(UserRepository::class)->findOneBy(['tenantId' => $repository->getTenantId()]) ?? self::fail('The tenant has a user.');
    }

    /**
     * @return list<string>
     */
    private static function scratchEntries(string $scratch): array
    {
        return array_values(array_diff(scandir($scratch) ?: [], ['.', '..']));
    }
}
