<?php

declare(strict_types=1);

namespace App\Tests\Functional\Anchoring;

use App\Domain\Identity\RepositoryId;
use App\Entity\User;
use App\Tenancy\TenantContext;
use App\Tests\Support\Api\AgentApiFixtures;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Q3 and get_request with `at` and `context`. The request anchors lines 20 to 22 of the invoice totals.
 */
final class GetRequestAtTest extends WebTestCase
{
    use AgentApiFixtures;
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use McpAssertions;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const string LEDGER = 'src/Legacy/Ledger.php';

    private GitRepositoryBuilder $source;

    private KernelBrowser $client;

    private User $user;

    private string $repositoryId;

    private string $reviewId;

    private string $requestId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        $ledger = implode('', array_map(static fn (int $line): string => "    \$entry += {$line};\n", range(1, 500)));
        $this->source->commit(self::codeFiles() + [self::LEDGER => $ledger]);
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->repositoryId = self::connectAndClone($this->client);
        $this->reviewId = (string) self::startReviewThroughApi($this->client, $this->repositoryId)['id'];
        $this->requestId = (string) self::openRequest($this->client, $this->reviewId, ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 20, 'endLine' => 22]])['id'];
    }

    #[TestDox('get_request with at equals the REST body of Q3 with at')]
    public function testGetRequestWithAtEqualsRestBody(): void
    {
        $later = $this->source->commit([self::INVOICE_TOTALS => "<?php\n\n".self::codeFiles()[self::INVOICE_TOTALS]]);
        [, $secret] = self::storeToken($this->user);

        $rest = self::assertApiResponse(self::bearerRequest($this->client, $secret, 'GET', "/api/v1/requests/{$this->requestId}?at={$later}"), 200, 'request.schema.json');
        $mcp = new McpTestClient($this->client, $secret);
        $tool = self::assertToolResult($mcp, $mcp->callTool('get_request', ['id' => $this->requestId, 'at' => substr($later, 0, 12)]), 'get_request');

        self::assertSame($rest, $tool);
        self::assertSame(
            ['target' => 'requested', 'targetSha' => $later, 'status' => 'shifted', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 22, 'endLine' => 24], 'confidence' => 1, 'reason' => null],
            $rest['location'],
        );
    }

    #[TestDox('An unknown commit gives commit_not_found after one fetch')]
    public function testUnknownCommitGivesCommitNotFoundAfterOneFetch(): void
    {
        $fetches = self::countFetches($this->client);
        $missing = str_repeat('d', 40);

        $error = self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/requests/{$this->requestId}?at={$missing}"), 422, 'commit_not_found');

        self::assertSame(['commit' => $missing], $error['details']);
        self::assertSame(1, $fetches());
    }

    #[TestDox('An ambiguous prefix gives invalid_input on at')]
    public function testAmbiguousPrefixGivesInvalidInput(): void
    {
        $this->source->branchCommitsWithAmbiguousPrefix();

        $error = self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/requests/{$this->requestId}?at=".GitRepositoryBuilder::AMBIGUOUS_PREFIX), 422, 'invalid_input');

        self::assertSame(['at'], array_column((array) ($error['details']['violations'] ?? []), 'field'));
    }

    #[TestDox('context returns an excerpt of at most 400 lines')]
    public function testContextReturnsExcerptOfAtMost400Lines(): void
    {
        $ledgerRequestId = (string) self::openRequest($this->client, $this->reviewId, ['kind' => 'range', 'path' => self::LEDGER, 'range' => ['startLine' => 2, 'endLine' => 451]])['id'];

        $small = $this->readWithQuery($this->requestId, 'context=2')['excerpt'];
        $large = $this->readWithQuery($ledgerRequestId, 'context=50')['excerpt'];

        self::assertSame([
            'targetSha' => $this->source->revParse('main'),
            'path' => self::INVOICE_TOTALS,
            'startLine' => 18,
            'endLine' => 24,
            'lines' => array_map(static fn (int $line): string => "    \$total += {$line};", range(18, 24)),
            'truncated' => false,
        ], $small);
        self::assertIsArray($large);
        self::assertSame([1, 400, 400, '    $entry += 400;', true], [$large['startLine'], $large['endLine'], count((array) $large['lines']), ((array) $large['lines'])[399], $large['truncated']]);
    }

    #[TestDox('Without a clone, location and outdated are null, and at gives repository_unavailable')]
    public function testWithoutCloneLocationIsNullAndAtGivesRepositoryUnavailable(): void
    {
        self::runQueuedMessages();
        self::assertFalse(self::readRequest($this->client, $this->requestId)['outdated']);
        self::getContainer()->get(TenantContext::class)->set($this->user->getTenantId());
        self::fetchRepository()->removeAccess(self::freshRepository(RepositoryId::fromString($this->repositoryId)));
        self::runQueuedMessages();

        $read = $this->readWithQuery($this->requestId, '');
        $error = self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/requests/{$this->requestId}?at=".$this->source->revParse('main')), 409, 'repository_unavailable');

        self::assertSame([null, null], [$read['location'], $read['outdated']]);
        self::assertSame(['repositoryId' => $this->repositoryId, 'reason' => 'removed'], $error['details']);
    }

    /**
     * @return array<string, mixed> The Request
     */
    private function readWithQuery(string $requestId, string $query): array
    {
        return self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/requests/{$requestId}?{$query}"), 200, 'request.schema.json');
    }
}
