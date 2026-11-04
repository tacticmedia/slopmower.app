<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitHub;

use App\Application\RequestCommands;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
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
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * D112: a Pass-through repository through the HTTP API, read from the git-backed GitHub fake.
 */
final class PassThroughFlowTest extends WebTestCase
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

    private const string LOGO = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR";

    #[TestDox('A Pass-through repository serves every read from GitHub and stores no code')]
    public function testFlowStoresNoCode(): void
    {
        $clock = self::mockTime('2026-11-04 10:00:00');
        $this->useLocalGitHub();
        $scratch = $this->temporaryDirectory();
        $this->setEnvironmentVariable('APP_SCRATCH_DIR', $scratch);
        // The index job parses through one scratch copy.
        $this->setEnvironmentVariable('APP_INDEX_MAX_BLOB_READS', '0');
        $source = $this->gitHubSource();
        $head = $source->commit([self::INVOICE_PATH => self::INVOICE, 'logo.png' => self::LOGO], 'Add the invoice');
        $source->git('push', '--quiet', 'origin', 'main');
        $client = static::createClient();
        $user = TenantFixtures::of(self::getContainer())->withLogin('octocat')->withGitHubUser(1)->create();
        $client->loginUser($user, 'web');
        self::storeInstallation($user->getTenantId());
        self::storeCandidate($user->getTenantId());

        $repositoryId = (string) self::assertApiResponse(self::apiRequest($client, 'POST', '/api/v1/repositories', ['source' => 'github', 'githubRepositoryId' => 812345, 'codeAccess' => 'pass_through']), 201, 'repository.schema.json')['id'];
        self::runQueuedMessages();
        $repository = self::assertApiResponse(self::apiRequest($client, 'GET', "/api/v1/repositories/{$repositoryId}"), 200, 'repository.schema.json');
        self::assertSame(['pass_through', 'idle', $head], [$repository['codeAccess'], $repository['sync']['state'] ?? null, $repository['sync']['defaultBranchHead'] ?? null]);

        $reviewId = (string) self::assertApiResponse(self::apiRequest($client, 'POST', "/api/v1/repositories/{$repositoryId}/reviews", ['ref' => substr($head, 0, 7)]), 201, 'review.schema.json')['id'];
        self::runQueuedMessages();
        self::assertContains('acme/billing', self::getContainer()->get(GitHubResponses::class)->repositoryCalls(), 'The scratch copy reads the GitHub size.');
        $review = "/api/v1/reviews/{$reviewId}";
        $this->assertCodeReads($client, $review, $repositoryId, $head);

        $requestId = (string) self::assertApiResponse(self::apiRequest($client, 'POST', "{$review}/requests", [
            'anchor' => ['kind' => 'range', 'path' => self::INVOICE_PATH, 'range' => ['startLine' => 7, 'endLine' => 10]],
            'body' => 'Return Money.',
        ]), 201, 'request.schema.json')['id'];
        self::assertApiResponse(self::apiRequest($client, 'POST', "{$review}/drafts", [
            'kind' => 'new_request',
            'anchor' => ['kind' => 'range', 'path' => self::INVOICE_PATH, 'range' => ['startLine' => 8, 'endLine' => 9]],
            'body' => 'Name the sum.',
        ]), 201, 'draft.schema.json');
        [, $secret] = self::storeToken($user);
        self::assertApiResponse(self::bearerRequest($client, $secret, 'POST', "/api/v1/requests/{$requestId}/claim"), 200, 'request-command-result.schema.json');
        $source->git('mv', self::INVOICE_PATH, 'src/Billing/Bill.php');
        $fix = $source->commit(['logo.png' => self::LOGO."\0more"], 'Rename the invoice');

        $linked = self::assertApiResponse(self::bearerRequest($client, $secret, 'POST', '/api/v1/commit-links', ['commitSha' => $fix, 'requestIds' => [$requestId]]), 200, 'link-commits-result.schema.json');
        self::assertSame('pending', $linked['state'] ?? null);
        // The delayed fetch of Q7 runs before the push.
        $clock->sleep(RequestCommands::FETCH_DELAY_MILLISECONDS / 1000 + 1);
        self::runQueuedMessages();
        $source->git('push', '--quiet', 'origin', 'main');
        self::assertSame(202, self::deliverFixture($client, 'push')->getStatusCode());
        self::runQueuedMessages();

        $request = self::assertApiResponse(self::apiRequest($client, 'GET', "/api/v1/requests/{$requestId}"), 200, 'request.schema.json');
        self::assertSame('available', $request['commits'][0]['state'] ?? null);
        $diff = self::assertApiResponse(self::apiRequest($client, 'GET', "/api/v1/requests/{$requestId}/commits/{$fix}"), 200, 'commit-diff.schema.json');
        $files = array_column($diff['files'], null, 'path');
        self::assertSame(['renamed', self::INVOICE_PATH, false], [$files['src/Billing/Bill.php']['status'] ?? null, $files['src/Billing/Bill.php']['oldPath'] ?? null, $files['src/Billing/Bill.php']['binary'] ?? null]);
        self::assertSame(['modified', true], [$files['logo.png']['status'] ?? null, $files['logo.png']['binary'] ?? null]);
        self::assertApiResponse(self::bearerRequest($client, $secret, 'POST', "/api/v1/requests/{$requestId}/ready", ['note' => 'Renamed.']), 200, 'request-command-result.schema.json');

        self::assertDirectoryDoesNotExist(self::getContainer()->get(TenantDataPaths::class)->clonePath($user->getTenantId(), RepositoryId::fromString($repositoryId)));
        self::assertSame([], array_values(array_diff(scandir($scratch) ?: [], ['.', '..'])), 'The scratch directory is empty.');
        self::assertSame([0, 0, 0], self::rowsWithCodeText($user->getTenantId()));
    }

    private function assertCodeReads(KernelBrowser $client, string $review, string $repositoryId, string $head): void
    {
        $tree = self::assertApiResponse(self::apiRequest($client, 'GET', "{$review}/tree?recursive=1"), 200, 'lists.schema.json#/$defs/TreeListing');
        self::assertSame(['README.md', 'logo.png', self::INVOICE_PATH], array_column($tree['items'], 'path'));
        $file = self::assertApiResponse(self::apiRequest($client, 'GET', "{$review}/file?path=".rawurlencode(self::INVOICE_PATH)), 200, 'file-content.schema.json');
        self::assertSame([11, false], [$file['lineCount'], $file['binary']]);
        $client->request('GET', "{$review}/raw?path=logo.png");
        self::assertSame([self::LOGO, 'application/octet-stream'], [$client->getInternalResponse()->getContent(), $client->getResponse()->headers->get('Content-Type')]);
        $blame = self::assertApiResponse(self::apiRequest($client, 'GET', "{$review}/blame?path=".rawurlencode(self::INVOICE_PATH)), 200, 'blame.schema.json');
        self::assertSame([false, [[1, 11, $head]]], [$blame['ignoreRevsFile'], array_map(static fn (array $range): array => [$range['startLine'], $range['endLine'], $range['commitSha']], $blame['ranges'])]);
        $commits = self::assertApiResponse(self::apiRequest($client, 'GET', "/api/v1/repositories/{$repositoryId}/commits"), 200, 'lists.schema.json#/$defs/CommitLog');
        self::assertSame([$head, 'Add the invoice'], [$commits['items'][0]['sha'] ?? null, $commits['items'][0]['subject'] ?? null]);
        $symbols = self::assertApiResponse(self::apiRequest($client, 'GET', "{$review}/symbols?path=".rawurlencode(self::INVOICE_PATH)), 200, 'lists.schema.json#/$defs/SymbolList');
        self::assertContains('App\Billing\Invoice::total()', array_column($symbols['items'], 'fqn'));
        $declarations = self::assertApiResponse(self::apiRequest($client, 'GET', "{$review}/declarations?path=".rawurlencode(self::INVOICE_PATH).'&name=Invoice'), 200, 'lists.schema.json#/$defs/DeclarationList');
        self::assertSame([self::INVOICE_PATH], array_column($declarations['items'], 'path'));
    }

    /**
     * @return list<int> The review_event, change_request, and draft rows of the tenant that hold snapshot text
     */
    private static function rowsWithCodeText(TenantId $tenantId): array
    {
        $connection = self::getContainer()->get(Connection::class);

        return array_map(
            static fn (string $sql): int => (int) $connection->fetchOne($sql, [$tenantId->toRfc4122()]),
            [
                'SELECT count(*) FROM review_event WHERE tenant_id = ? AND payload::text LIKE \'%"lines"%\'',
                'SELECT count(*) FROM change_request WHERE tenant_id = ? AND state::text LIKE \'%"lines"%\'',
                'SELECT count(*) FROM draft WHERE tenant_id = ? AND anchor::text LIKE \'%"lines"%\'',
            ],
        );
    }
}
