<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;

/**
 * The string form of an integer or boolean input, docs/specs/mcp.md section 3, "Adapter" (D146).
 */
final class StringArgumentListenerTest extends WebTestCase
{
    use ApiFixtures;
    use McpAssertions;
    use ResetDatabase;

    private McpTestClient $mcp;

    protected function setUp(): void
    {
        $client = static::createClient();
        $user = self::signIn($client);
        $this->mcp = new McpTestClient($client, self::storeToken($user)[1]);
        self::storeProject($user->getTenantId(), 'SM');
    }

    #[TestDox('list_issues takes limit "1", noActivity "true", and activeWithinHours "1" of type ["null", "integer"] as 1, true, and 1')]
    public function testCastsIntegerAndBooleanStrings(): void
    {
        for ($created = 0; $created < 3; ++$created) {
            $this->mcp->callTool('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change']);
        }

        $this->mcp->callTool('comment_on_issue', ['issue' => 'SM-1', 'body' => 'Started.', 'session' => 'billing-3f9a']);
        $page = self::assertToolResult($this->mcp, $this->mcp->callTool('list_issues', ['project' => 'SM', 'limit' => '1', 'noActivity' => 'true']), 'list_issues');

        $active = self::assertToolResult($this->mcp, $this->mcp->callTool('list_issues', ['project' => 'SM', 'activeWithinHours' => '1']), 'list_issues');

        self::assertSame(['SM-2'], array_column($page['items'], 'key'));
        self::assertNotNull($page['nextCursor']);
        self::assertSame(['SM-1'], array_column($active['items'], 'key'));
    }

    /**
     * @return array<string, array{string, array<string, string>, string}>
     */
    public static function uncastStrings(): array
    {
        return [
            'no integer' => ['list_issues', ['project' => 'SM', 'limit' => 'x'], '/limit'],
            'a plus sign' => ['list_issues', ['project' => 'SM', 'limit' => '+1'], '/limit'],
            'a leading zero' => ['list_issues', ['project' => 'SM', 'limit' => '007'], '/limit'],
            'more than 64 bits for an input without maximum' => ['get_updates', ['review' => (string) new Ulid(), 'cursor' => '9223372036854775808'], '/cursor'],
            'upper case' => ['list_issues', ['project' => 'SM', 'noActivity' => 'TRUE'], '/noActivity'],
        ];
    }

    /**
     * @param array<string, string> $arguments
     */
    #[DataProvider('uncastStrings')]
    #[TestDox('A string with $_dataName stays a string and gets -32602 on its input')]
    public function testOtherStringIsRefused(string $tool, array $arguments, string $pointer): void
    {
        $error = self::assertJsonRpcError($this->mcp->callToolResponse($tool, $arguments), -32602);

        self::assertIsArray($error['data']);
        self::assertSame([$pointer], array_column($error['data']['validation_errors'], 'pointer'));
    }

    #[TestDox('An unknown tool gets the -32602 of the SDK without data')]
    public function testUnknownToolIsLeftToTheSdk(): void
    {
        $error = self::assertJsonRpcError($this->mcp->callToolResponse('no_such_tool', ['limit' => '1']), -32602);

        self::assertSame('Tool not found: "no_such_tool".', $error['message']);
        self::assertArrayNotHasKey('data', $error);
    }
}
