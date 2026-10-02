<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Mcp\McpTestClient;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * tests/Fixtures/mcp/tools-list.json holds the names, titles, descriptions, input schemas, and annotations of
 * docs/specs/mcp.md section 4, copied verbatim.
 */
final class ToolsListContractTest extends WebTestCase
{
    use ApiFixtures;
    use ResetDatabase;

    private const array COMPARED_MEMBERS = ['name', 'title', 'description', 'inputSchema', 'annotations'];

    #[TestDox('tools/list names, input schemas, and annotations of the five read tools equal docs/specs')]
    public function testToolsEqualDocsSpecs(): void
    {
        $client = static::createClient();
        [, $secret] = self::storeToken(TenantFixtures::of(self::getContainer())->create());
        $mcp = new McpTestClient($client, $secret);
        $mcp->initialize();

        $listed = McpTestClient::result($mcp->request('tools/list'))['tools'];

        self::assertIsArray($listed);
        $byName = array_column(array_map(static fn (array $tool): array => array_intersect_key($tool, array_flip(self::COMPARED_MEMBERS)), $listed), null, 'name');
        /** @var array{tools: list<array<string, mixed>>} $expected */
        $expected = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/mcp/tools-list.json'), true, flags: \JSON_THROW_ON_ERROR);
        $expectedByName = array_column($expected['tools'], null, 'name');
        ksort($byName);
        ksort($expectedByName);

        self::assertSame(array_keys($expectedByName), array_keys($byName), 'The listed tool names.');

        foreach ($expectedByName as $name => $tool) {
            self::assertSame($tool, array_merge(array_flip(self::COMPARED_MEMBERS), $byName[$name]), "The tool {$name}.");
        }
    }
}
