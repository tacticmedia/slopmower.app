<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\SchemaValidator;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * docs/specs/mcp.md section 1, "Rate limit".
 */
final class McpRateLimitTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use EnvironmentVariables;
    use McpAssertions;
    use ResetDatabase;

    #[TestDox('A tool call over the limit returns isError with rate_limited')]
    public function testToolCallOverTheLimitIsAnError(): void
    {
        $this->setEnvironmentVariable('APP_RATE_LIMIT_API_TOKEN_PER_MINUTE', '1');
        $mcp = $this->mcpClient();
        $mcp->initialize();
        $mcp->callTool('list_repositories');

        $error = self::assertToolError($mcp->callTool('list_repositories'), 'rate_limited');

        self::assertSame(['retryAfterSeconds' => 60, 'limit' => 1], $error['details']);
    }

    #[TestDox('An HTTP request over the mcp_http limit gets 429 before JSON-RPC')]
    public function testRequestOverTheTransportLimitGets429(): void
    {
        $this->setEnvironmentVariable('APP_RATE_LIMIT_MCP_HTTP_PER_MINUTE', '1');
        $mcp = $this->mcpClient();
        $mcp->initializeResponse();

        $response = $mcp->initializeResponse();

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(['60', '1', '0'], [$response->getHeader('Retry-After'), $response->getHeader('X-RateLimit-Limit'), $response->getHeader('X-RateLimit-Remaining')]);
        self::assertSame([], SchemaValidator::errors($response->getContent(), 'error.schema.json'));
        self::assertSame('rate_limited', json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR)['error']['code']);
    }

    private function mcpClient(): McpTestClient
    {
        self::mockTime('2026-10-13 09:00:00');
        $client = static::createClient();
        [, $secret] = self::storeToken(TenantFixtures::of(self::getContainer())->create());

        return new McpTestClient($client, $secret);
    }
}
