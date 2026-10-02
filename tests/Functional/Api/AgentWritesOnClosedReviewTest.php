<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Api\AgentApiFixtures;
use App\Tests\Support\Api\ApiFixtures;
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

final class AgentWritesOnClosedReviewTest extends WebTestCase
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

    /**
     * The agent claimed the request and linked the review commit, so unlink passes its input check. Then the user
     * closed the review.
     */
    #[TestDox('Every write tool and agent endpoint gives review_closed on a closed review')]
    public function testEveryAgentWriteGivesReviewClosed(): void
    {
        $this->useTemporaryLocalSources();
        $commit = $this->newSource()->commit(self::codeFiles());
        $client = static::createClient();
        $user = self::signIn($client);
        $reviewId = self::startCodeReview($client);
        $requestId = (string) self::openRequest($client, $reviewId)['id'];
        [, $secret] = self::storeToken($user);
        self::agentRequest($client, $secret, 'POST', "/api/v1/requests/{$requestId}/claim");
        self::agentRequest($client, $secret, 'POST', '/api/v1/commit-links', ['commitSha' => $commit, 'requestIds' => [$requestId]], schema: 'link-commits-result.schema.json');
        $closedAt = self::assertApiResponse(self::apiRequest($client, 'POST', "/api/v1/reviews/{$reviewId}/close"), 200, 'review.schema.json')['closedAt'];
        $details = ['reviewId' => $reviewId, 'closedAt' => $closedAt];
        $mcp = new McpTestClient($client, $secret);
        $tools = [
            'claim_request' => ['id' => $requestId],
            'release_request' => ['id' => $requestId],
            'reply_to_request' => ['id' => $requestId, 'body' => 'Too late.'],
            'link_commits' => ['commitSha' => $commit, 'requestIds' => [$requestId]],
            'unlink_commit' => ['commitSha' => $commit, 'requestId' => $requestId],
            'mark_ready_for_review' => ['id' => $requestId, 'note' => 'Too late.'],
            'mark_wont_do' => ['id' => $requestId, 'reason' => self::WONT_DO_REASON],
        ];
        $endpoints = [
            ['POST', "/api/v1/requests/{$requestId}/comments", ['body' => 'Too late.']],
            ['POST', "/api/v1/requests/{$requestId}/claim", null],
            ['POST', "/api/v1/requests/{$requestId}/release", null],
            ['POST', '/api/v1/commit-links', ['commitSha' => $commit, 'requestIds' => [$requestId]]],
            ['DELETE', "/api/v1/requests/{$requestId}/commits/{$commit}", null],
            ['POST', "/api/v1/requests/{$requestId}/ready", ['note' => 'Too late.']],
            ['POST', "/api/v1/requests/{$requestId}/wont-do", ['reason' => self::WONT_DO_REASON]],
        ];

        foreach ($tools as $tool => $arguments) {
            self::assertSame($details, self::assertToolError($mcp->callTool($tool, $arguments), 'review_closed')['details'], $tool);
        }

        foreach ($endpoints as [$method, $path, $body]) {
            self::assertSame($details, self::assertApiError(self::bearerRequest($client, $secret, $method, $path, $body), 409, 'review_closed')['details'], "{$method} {$path}");
        }
    }
}
