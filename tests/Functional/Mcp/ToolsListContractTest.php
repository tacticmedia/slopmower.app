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

/**
 * tests/Fixtures/mcp/tools-list.json holds the names, titles, descriptions, input schemas, and annotations of
 * docs/specs/mcp.md section 4, copied verbatim.
 */
final class ToolsListContractTest extends WebTestCase
{
    use ApiFixtures;
    use ResetDatabase;
    use SpecsFiles;

    private const array COMPARED_MEMBERS = ['name', 'title', 'description', 'inputSchema', 'annotations'];

    /** The definition of each tool input that has a pattern, by input name. */
    private const array PATTERN_DEFINITIONS = [
        'at' => 'common.schema.json#/$defs/CommitShaPrefix',
        'commitSha' => 'common.schema.json#/$defs/CommitSha',
        'id' => 'common.schema.json#/$defs/Ulid',
        'requestId' => 'common.schema.json#/$defs/Ulid',
        'requestIds' => 'common.schema.json#/$defs/Ulid',
        'review' => 'common.schema.json#/$defs/Ulid',
        'url' => 'common.schema.json#/$defs/PullRequestUrl',
    ];

    #[TestDox('tools/list names, input schemas, and annotations of all 18 tools equal docs/specs')]
    public function testToolsEqualDocsSpecs(): void
    {
        $listed = $this->listedTools();

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

    #[TestDox('Each tool input pattern equals the pattern of its common.schema.json definition')]
    public function testInputPatternsEqualCommonDefinitions(): void
    {
        $definitions = self::specsJson('schemas/common.schema.json')['$defs'];
        self::assertIsArray($definitions);
        $checked = 0;

        foreach ($this->listedTools() as $tool) {
            foreach ($tool['inputSchema']['properties'] as $input => $property) {
                $pattern = $property['pattern'] ?? $property['items']['pattern'] ?? null;

                if (null === $pattern) {
                    continue;
                }

                $reference = self::PATTERN_DEFINITIONS[$input] ?? self::fail("Give the input {$tool['name']}.{$input} its definition.");
                self::assertSame($definitions[substr($reference, \strlen('common.schema.json#/$defs/'))]['pattern'], $pattern, "{$tool['name']}.{$input}");
                ++$checked;
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    /**
     * @return list<array{name: string, inputSchema: array{properties: array<string, array<string, mixed>>, required?: list<string>}, ...}>
     */
    private function listedTools(): array
    {
        $client = static::createClient();
        [, $secret] = self::storeToken(TenantFixtures::of(self::getContainer())->create());

        return new McpTestClient($client, $secret)->tools();
    }
}
