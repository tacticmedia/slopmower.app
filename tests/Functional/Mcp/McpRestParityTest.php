<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Application\RequestCommands;
use App\Domain\Actor\Actor;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\Reply;
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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/specs/mcp.md section 6. The agent claimed and answered a range request; a file request is open. REST and MCP
 * use the same token.
 */
final class McpRestParityTest extends WebTestCase
{
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

    private KernelBrowser $client;

    private McpTestClient $mcp;

    private string $secret;

    private string $reviewId;

    private string $claimedRequestId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $user = self::signIn($this->client);
        $this->reviewId = self::startCodeReview($this->client);
        $this->claimedRequestId = (string) self::openRequest($this->client, $this->reviewId, ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 3, 'endLine' => 5]])['id'];
        self::openRequest($this->client, $this->reviewId);
        $claimed = RequestId::fromString($this->claimedRequestId);
        self::asAgent($user, static function (RequestCommands $commands, Actor $agent) use ($claimed): void {
            $commands->claim(new Claim($claimed), $agent);
            $commands->reply(new Reply($claimed, 'Should the tax lines use `Money` too?', true), $agent);
        });
        self::requestCommand($this->client, $this->claimedRequestId, 'comments', ['body' => 'Yes, the tax lines too.']);
        [, $this->secret] = self::storeToken($user);
        $this->mcp = new McpTestClient($this->client, $this->secret);
    }

    #[TestDox('get_request equals the REST body of Q3')]
    public function testGetRequestEqualsQ3(): void
    {
        $rest = self::assertApiResponse(self::bearerRequest($this->client, $this->secret, 'GET', "/api/v1/requests/{$this->claimedRequestId}"), 200, 'request.schema.json');

        $mcp = self::assertToolResult($this->mcp, $this->mcp->callTool('get_request', ['id' => $this->claimedRequestId]), 'get_request');

        self::assertSame($rest, $mcp);
    }

    #[TestDox('list_requests equals the REST body of Q1')]
    public function testListRequestsEqualsQ1(): void
    {
        $rest = self::assertApiResponse(self::bearerRequest($this->client, $this->secret, 'GET', "/api/v1/reviews/{$this->reviewId}/requests"), 200, 'lists.schema.json#/$defs/RequestPage');

        $mcp = self::assertToolResult($this->mcp, $this->mcp->callTool('list_requests', ['review' => $this->reviewId]), 'list_requests');

        self::assertSame($rest, $mcp);
        self::assertSame(2, $mcp['total']);
    }
}
