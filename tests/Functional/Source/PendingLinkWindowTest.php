<?php

declare(strict_types=1);

namespace App\Tests\Functional\Source;

use App\Entity\User;
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
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * D90. The linked commit is only on a ref outside the fetch refspecs, so only a fetch by SHA brings it.
 */
final class PendingLinkWindowTest extends WebTestCase
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

    private const string NOTE = 'Extracted TaxRounding. The billing tests pass.';

    private GitRepositoryBuilder $source;

    private KernelBrowser $client;

    private User $user;

    private McpTestClient $mcp;

    private string $requestId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        $this->source->commit(self::codeFiles());
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $repositoryId = self::connectAndClone($this->client);
        $this->requestId = (string) self::openRequest($this->client, (string) self::startReviewThroughApi($this->client, $repositoryId)['id'])['id'];
        [, $secret] = self::storeToken($this->user);
        $this->mcp = new McpTestClient($this->client, $secret);
        self::assertToolResult($this->mcp, $this->mcp->callTool('claim_request', ['id' => $this->requestId]), 'claim_request');

        $fix = $this->source->switch('fix')->commit(['src/Billing/TaxRounding.php' => "<?php\n\nfinal class TaxRounding\n{\n}\n"], 'Extract TaxRounding');
        $this->source->git('update-ref', 'refs/other/fix', $fix);
        $this->source->switch('main')->git('branch', '--delete', '--force', 'fix');
        self::assertSame('pending', self::assertToolResult($this->mcp, $this->mcp->callTool('link_commits', ['commitSha' => $fix, 'requestIds' => [$this->requestId]]), 'link_commits')['state']);
    }

    #[TestDox('A pending link of 6 days ago is fetched by SHA')]
    public function testRecentLinkIsFetched(): void
    {
        $this->linkedDaysAgo(6);

        $result = self::assertToolResult($this->mcp, $this->mcp->callTool('mark_ready_for_review', ['id' => $this->requestId, 'note' => self::NOTE]), 'mark_ready_for_review');

        self::assertSame(['ready_for_review', 0], [$result['request']['status'], $result['request']['pendingCommitCount']]);
    }

    #[TestDox('A pending link of 8 days ago is not fetched by SHA, so it stays pending')]
    public function testOldLinkIsNotFetched(): void
    {
        $this->linkedDaysAgo(8);

        self::assertToolError($this->mcp->callTool('mark_ready_for_review', ['id' => $this->requestId, 'note' => self::NOTE]), 'commits_not_available');
    }

    private function linkedDaysAgo(int $days): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE commit_link SET linked_at = linked_at - make_interval(days => ?) WHERE tenant_id = ?',
            [$days, $this->user->getTenantId()->toRfc4122()],
        );
    }
}
