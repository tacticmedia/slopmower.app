<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

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

/**
 * The user opened a request through the API; the agent declines it through MCP.
 */
final class WontDoToolTest extends WebTestCase
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

    private McpTestClient $mcp;

    private string $requestId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $client = static::createClient();
        $user = self::signIn($client);
        $this->requestId = (string) self::openRequest($client, self::startCodeReview($client))['id'];
        $this->mcp = new McpTestClient($client, self::storeToken($user)[1]);
    }

    #[TestDox('A reason shorter than 20 characters gives -32602')]
    public function testShortReasonGivesSchemaError(): void
    {
        $error = self::assertJsonRpcError($this->mcp->callToolResponse('mark_wont_do', ['id' => $this->requestId, 'reason' => 'Too short.']), -32602);

        self::assertIsArray($error['data']['validation_errors'][0] ?? null);
        self::assertSame(['/reason', 'minLength'], [$error['data']['validation_errors'][0]['pointer'], $error['data']['validation_errors'][0]['keyword']]);
    }

    #[TestDox('A reason with fewer than 20 characters after trimming gives isError invalid_input')]
    public function testShortReasonAfterTrimmingGivesInvalidInput(): void
    {
        $error = self::assertToolError($this->mcp->callTool('mark_wont_do', ['id' => $this->requestId, 'reason' => '      Already done.       ']), 'invalid_input');

        self::assertSame('reason', $error['details']['violations'][0]['field'] ?? null);
    }

    #[TestDox('A valid reason sets wont_do and waits for the user')]
    public function testValidReasonSetsWontDo(): void
    {
        $result = self::assertToolResult($this->mcp, $this->mcp->callTool('mark_wont_do', ['id' => $this->requestId, 'reason' => self::WONT_DO_REASON]), 'mark_wont_do');

        $expected = ['status' => 'wont_do', 'awaiting' => 'user', 'resolution' => 'wont_do', 'closed' => false];
        self::assertSame($expected, array_intersect_key($result['request'], $expected));
        self::assertSame([['request.wont_do', 'agent', self::WONT_DO_REASON]], array_map(static fn (array $event): array => [$event['type'], $event['actor']['kind'], $event['body']], $result['events']));
    }
}
