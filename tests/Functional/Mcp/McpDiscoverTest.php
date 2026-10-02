<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Mcp\McpTestClient;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class McpDiscoverTest extends WebTestCase
{
    use ApiFixtures;
    use ResetDatabase;

    #[TestDox('server/discover of protocol 2026-07-28 works without a session')]
    public function testDiscoverWithoutSession(): void
    {
        $client = static::createClient();
        [, $secret] = self::storeToken(TenantFixtures::of(self::getContainer())->create());
        $mcp = new McpTestClient($client, $secret);

        $discovered = McpTestClient::result($mcp->modernRequest('server/discover'));

        self::assertNull($mcp->lastResponse()->getHeader('Mcp-Session-Id'));
        self::assertIsArray($discovered['supportedVersions']);
        self::assertContains(McpTestClient::MODERN_VERSION, $discovered['supportedVersions']);
        self::assertIsArray($discovered['capabilities']);
        self::assertArrayHasKey('tools', $discovered['capabilities']);
    }
}
