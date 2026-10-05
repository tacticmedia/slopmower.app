<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Entity\User;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\DataCollector\LoggerDataCollector;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Uid\Ulid;

/**
 * docs/specs/mcp.md section 5.
 */
final class McpErrorMappingTest extends WebTestCase
{
    use ApiFixtures;
    use McpAssertions;
    use ResetDatabase;

    #[TestDox('not_found gives isError with the Error JSON as text and no structuredContent')]
    public function testNotFoundGivesIsError(): void
    {
        [, $mcp] = $this->mcpClient();

        $error = self::assertToolError($mcp->callTool('get_request', ['id' => (string) new Ulid()]), 'not_found');

        self::assertNull($error['details']);
    }

    #[TestDox('A bad enum value gives JSON-RPC error -32602')]
    public function testBadEnumValueGivesInvalidParams(): void
    {
        [, $mcp] = $this->mcpClient();

        $error = self::assertJsonRpcError($mcp->callToolResponse('list_requests', ['review' => (string) new Ulid(), 'status' => 'done']), -32602);

        self::assertIsArray($error['data']);
        self::assertSame(['/status'], array_column($error['data']['validation_errors'], 'pointer'));
    }

    #[TestDox('A value that passes the input schema but breaks a rule of the REST query gives isError with invalid_input')]
    public function testSemanticCheckGivesInvalidInput(): void
    {
        [, $mcp, $user] = $this->mcpClient();
        $review = self::storeReview($user, 'billing');

        $error = self::assertToolError($mcp->callTool('list_requests', ['review' => $review->getId()->toString(), 'path' => '/src']), 'invalid_input');

        self::assertIsArray($error['details']);
        self::assertSame(['path'], array_column($error['details']['violations'], 'field'));
    }

    #[TestDox('An unexpected exception gives isError with internal_error, not -32603')]
    public function testUnexpectedExceptionGivesInternalError(): void
    {
        [$client, $mcp, $user] = $this->mcpClient();
        $request = self::storeRequest(self::storeReview($user, 'billing'));
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE change_request SET anchor = \'{"kind": "file"}\' WHERE tenant_id = :tenantId AND id = :id',
            ['tenantId' => $user->getTenantId()->toRfc4122(), 'id' => $request->getId()->toRfc4122()],
        );
        $mcp->initialize();
        $client->enableProfiler();

        $error = self::assertToolError($mcp->callTool('get_request', ['id' => $request->getId()->toString()]), 'internal_error');

        self::assertSame(['An unexpected error occurred.', null], [$error['message'], $error['details']]);
        $logs = self::profile($client)->getCollector('logger');
        self::assertInstanceOf(LoggerDataCollector::class, $logs);
        self::assertContains('The MCP tool {tool} failed.', array_map(strval(...), array_column($logs->getProcessedLogs(), 'message')));
    }

    /**
     * @return array{KernelBrowser, McpTestClient, User}
     */
    private function mcpClient(): array
    {
        $client = static::createClient();
        $user = TenantFixtures::of(self::getContainer())->create();
        [, $secret] = self::storeToken($user);

        return [$client, new McpTestClient($client, $secret), $user];
    }

    private static function profile(KernelBrowser $client): Profile
    {
        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);

        return $profile;
    }
}
