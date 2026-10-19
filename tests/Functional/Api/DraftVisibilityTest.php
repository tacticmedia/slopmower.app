<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\DraftApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DraftVisibilityTest extends WebTestCase
{
    use ApiFixtures;
    use DraftApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use McpAssertions;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    #[TestDox('MCP sees nothing before submit and everything after')]
    public function testMcpSeesDraftsOnlyAfterSubmit(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $client = static::createClient();
        $user = self::signIn($client);
        $reviewId = self::startCodeReview($client);
        self::createDraft($client, $reviewId, body: 'Use `Money` for totals.');
        self::createDraft($client, $reviewId, ['kind' => 'line', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 3, 'endLine' => 3]], 'Name this constant.');
        $mcp = new McpTestClient($client, self::storeToken($user)[1]);

        $before = self::agentView($mcp, $reviewId);
        self::assertApiResponse(self::apiRequest($client, 'POST', "/api/v1/reviews/{$reviewId}/drafts/submit", ['summary' => 'First pass.']), 201, 'batch-submission.schema.json');
        $after = self::agentView($mcp, $reviewId);

        self::assertSame(['requests' => [], 'waiting' => [], 'reviewEvents' => ['review.started']], $before);
        self::assertSame([
            'requests' => ['Use Money for totals.', 'Name this constant.'],
            'waiting' => ['Use Money for totals.', 'Name this constant.'],
            'reviewEvents' => ['review.started', 'batch.submitted'],
        ], $after);
    }

    /**
     * The summaries of list_requests and get_updates, and the review event types.
     *
     * @return array{requests: list<mixed>, waiting: list<mixed>, reviewEvents: list<mixed>}
     */
    private static function agentView(McpTestClient $mcp, string $reviewId): array
    {
        $list = self::assertToolResult($mcp, $mcp->callTool('list_requests', ['review' => $reviewId]), 'list_requests');
        $updates = self::assertToolResult($mcp, $mcp->callTool('get_updates', ['review' => $reviewId]), 'get_updates');

        return [
            'requests' => array_column($list['items'], 'summary'),
            'waiting' => array_column(array_column($updates['requests'], 'request'), 'summary'),
            'reviewEvents' => array_column($updates['reviewEvents'], 'type'),
        ];
    }
}
