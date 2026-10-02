<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Mcp\McpTestClient;
use App\Tests\Support\Specs\SpecsFiles;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

final class McpHandshakeTest extends WebTestCase
{
    use ApiFixtures;
    use ResetDatabase;
    use SpecsFiles;

    #[TestDox('initialize with protocol 2025-11-25, the initialized notification, and ping succeed')]
    public function testHandshakeAndPing(): void
    {
        $client = static::createClient();
        [, $secret] = self::storeToken(TenantFixtures::of(self::getContainer())->create());
        $mcp = new McpTestClient($client, $secret);

        $initialized = $mcp->initialize(McpTestClient::HANDSHAKE_VERSION);
        $ping = $mcp->request('ping');

        self::assertSame(McpTestClient::HANDSHAKE_VERSION, $initialized['protocolVersion']);
        self::assertIsArray($initialized['serverInfo']);
        self::assertSame(['holistic-review', '1.0.0'], [$initialized['serverInfo']['name'], $initialized['serverInfo']['version']]);
        self::assertSame(self::specsCodeBlock('mcp.md', '## 2. Server instructions'), $initialized['instructions']);
        self::assertSame([], McpTestClient::result($ping));
    }

    #[TestDox('The session is stored under var/mcp-sessions')]
    public function testSessionIsStoredUnderVarMcpSessions(): void
    {
        $client = static::createClient();
        [$token, $secret] = self::storeToken(TenantFixtures::of(self::getContainer())->create());
        $mcp = new McpTestClient($client, $secret);

        $mcp->initialize();

        $storedId = Uuid::v5(new Uuid(Ulid::fromString($token->getId()->toString())->toRfc4122()), (string) $mcp->sessionId());
        self::assertFileExists(self::getContainer()->getParameter('kernel.project_dir')."/var/mcp-sessions/{$storedId}");
    }
}
