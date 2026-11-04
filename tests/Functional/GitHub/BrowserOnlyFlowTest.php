<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitHub;

use App\Application\RequestCommands;
use App\Domain\Identity\RepositoryId;
use App\Git\TenantDataPaths;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\GitHub\GitHubResponses;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\GitHubWebhooks;
use App\Tests\Support\GitHub\LocalGitHub;
use App\Tests\Support\Messenger\QueuedMessages;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * D114: a Browser only repository through the HTTP API, read from the git-backed GitHub fake.
 */
final class BrowserOnlyFlowTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use EnvironmentVariables;
    use GitHubRows;
    use GitHubWebhooks;
    use JsonApiAssertions;
    use LocalGitHub;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;

    private const string INVOICE_PATH = 'src/Billing/Invoice.php';

    private const string INVOICE = "<?php\n\nnamespace App\\Billing;\n\nfinal class Invoice\n{\n    public function total(): int\n    {\n        return 1 + 2;\n    }\n}\n";

    /** Endpoints that return file content or patches. The SHA media type of `commits/{ref}` returns a SHA only. */
    private const string CONTENT_CALL = '~^acme/billing/(git/blobs/|compare/|contents/|commits/[0-9a-f]{40}(\?|$))~';

    #[TestDox('A Browser only repository reads only metadata from GitHub and makes no scratch copy')]
    public function testFlowReadsOnlyMetadata(): void
    {
        $clock = self::mockTime('2026-11-04 10:00:00');
        $this->useLocalGitHub();
        $scratch = $this->temporaryDirectory().'/scratch';
        $this->setEnvironmentVariable('APP_SCRATCH_DIR', $scratch);
        $source = $this->gitHubSource();
        $head = $source->commit([self::INVOICE_PATH => self::INVOICE], 'Add the invoice');
        $source->git('push', '--quiet', 'origin', 'main');
        $client = static::createClient();
        // The fake keeps its transport log only in one container.
        $client->disableReboot();
        $user = TenantFixtures::of(self::getContainer())->withLogin('octocat')->withGitHubUser(1)->create();
        $client->loginUser($user, 'web');
        self::storeInstallation($user->getTenantId());
        self::storeCandidate($user->getTenantId());

        $repositoryId = (string) self::assertApiResponse(self::apiRequest($client, 'POST', '/api/v1/repositories', ['source' => 'github', 'githubRepositoryId' => 812345, 'codeAccess' => 'browser']), 201, 'repository.schema.json')['id'];
        self::runQueuedMessages();
        $reviewId = (string) self::assertApiResponse(self::apiRequest($client, 'POST', "/api/v1/repositories/{$repositoryId}/reviews", ['ref' => 'main']), 201, 'review.schema.json')['id'];
        self::runQueuedMessages();
        $review = "/api/v1/reviews/{$reviewId}";

        self::assertSame(['README.md', self::INVOICE_PATH], array_column(self::assertApiResponse(self::apiRequest($client, 'GET', "{$review}/tree?recursive=1"), 200, 'lists.schema.json#/$defs/TreeListing')['items'], 'path'));
        $file = self::assertApiResponse(self::apiRequest($client, 'GET', "{$review}/file?path=".rawurlencode(self::INVOICE_PATH)), 200, 'file-content.schema.json');
        self::assertSame([null, null, null], [$file['renderable'], $file['lineCount'], $file['rawUrl']]);
        $blame = self::assertApiResponse(self::apiRequest($client, 'GET', "{$review}/blame?path=".rawurlencode(self::INVOICE_PATH)), 200, 'blame.schema.json');
        self::assertSame([[1, 11, $head]], array_map(static fn (array $range): array => [$range['startLine'], $range['endLine'], $range['commitSha']], $blame['ranges']));
        self::assertSame($head, self::assertApiResponse(self::apiRequest($client, 'GET', "/api/v1/repositories/{$repositoryId}/commits"), 200, 'lists.schema.json#/$defs/CommitLog')['items'][0]['sha'] ?? null);

        $requestId = (string) self::assertApiResponse(self::apiRequest($client, 'POST', "{$review}/requests", [
            'anchor' => ['kind' => 'range', 'path' => self::INVOICE_PATH, 'range' => ['startLine' => 7, 'endLine' => 10]],
            'body' => 'Return Money.',
        ]), 201, 'request.schema.json')['id'];
        [, $secret] = self::storeToken($user);
        self::assertApiResponse(self::bearerRequest($client, $secret, 'POST', "/api/v1/requests/{$requestId}/claim"), 200, 'request-command-result.schema.json');
        $source->git('mv', self::INVOICE_PATH, 'src/Billing/Bill.php');
        $fix = $source->commit(['src/Billing/Tax.php' => "<?php\n"], 'Rename the invoice');
        self::bearerRequest($client, $secret, 'POST', '/api/v1/commit-links', ['commitSha' => $fix, 'requestIds' => [$requestId]]);
        $clock->sleep(RequestCommands::FETCH_DELAY_MILLISECONDS / 1000 + 1);
        self::runQueuedMessages();
        $source->git('push', '--quiet', 'origin', 'main');
        self::assertSame(202, self::deliverFixture($client, 'push')->getStatusCode());
        self::runQueuedMessages();

        $diff = self::assertApiResponse(self::apiRequest($client, 'GET', "/api/v1/requests/{$requestId}/commits/{$fix}"), 200, 'commit-diff.schema.json');
        self::assertSame(
            [['renamed', 'src/Billing/Bill.php', self::INVOICE_PATH, null], ['added', 'src/Billing/Tax.php', null, null]],
            array_map(static fn (array $file): array => [$file['status'], $file['path'], $file['oldPath'], $file['binary']], $diff['files']),
        );
        $request = self::assertApiResponse(self::apiRequest($client, 'GET', "/api/v1/requests/{$requestId}"), 200, 'request.schema.json');
        self::assertSame(['shifted', 'src/Billing/Bill.php', false, null], [$request['location']['status'] ?? null, $request['location']['path'] ?? null, $request['outdated'], $request['anchor']['snapshot']]);

        self::assertSame([], array_values(preg_grep(self::CONTENT_CALL, self::getContainer()->get(GitHubResponses::class)->repositoryCalls()) ?: []));
        self::assertDirectoryDoesNotExist($scratch);
        self::assertDirectoryDoesNotExist(self::getContainer()->get(TenantDataPaths::class)->clonePath($user->getTenantId(), RepositoryId::fromString($repositoryId)));
    }
}
