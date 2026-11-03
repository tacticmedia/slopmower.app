<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Mcp\BundledSchemas;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Mcp\McpTestClient;
use App\Tests\Support\Specs\SpecsFiles;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The tools of docs/specs/mcp-tools.json (D99).
 */
final class ToolsListContractTest extends WebTestCase
{
    use ApiFixtures;
    use ResetDatabase;
    use SpecsFiles;

    /** The definition of each tool input that has a pattern, by input name. */
    private const array PATTERN_DEFINITIONS = [
        'at' => 'common.schema.json#/$defs/CommitShaPrefix',
        'body' => 'common.schema.json#/$defs/NoNulText',
        'commitSha' => 'common.schema.json#/$defs/CommitSha',
        'cursor' => 'common.schema.json#/$defs/NoNulText',
        'diff' => 'common.schema.json#/$defs/NoNulText',
        'group' => 'common.schema.json#/$defs/NoNulText',
        'id' => 'common.schema.json#/$defs/Ulid',
        'note' => 'common.schema.json#/$defs/NoNulText',
        'path' => 'common.schema.json#/$defs/NoNulText',
        'plan' => 'common.schema.json#/$defs/NoNulText',
        'reason' => 'common.schema.json#/$defs/NoNulText',
        'ref' => 'common.schema.json#/$defs/NoNulText',
        'repository' => 'common.schema.json#/$defs/NoNulText',
        'requestId' => 'common.schema.json#/$defs/Ulid',
        'requestIds' => 'common.schema.json#/$defs/Ulid',
        'review' => 'common.schema.json#/$defs/Ulid',
        'symbol' => 'common.schema.json#/$defs/NoNulText',
        'title' => 'common.schema.json#/$defs/NoNulText',
        'url' => 'common.schema.json#/$defs/PullRequestUrl',
    ];

    #[TestDox('tools/list gives the tools of docs/specs/mcp-tools.json in its order, each with the bundled output schema of its reference')]
    public function testToolsEqualDocsSpecs(): void
    {
        $listed = $this->listedTools();
        $expected = self::specsJson('mcp-tools.json')['tools'];
        self::assertIsArray($expected);

        self::assertSame(array_column($expected, 'name'), array_column($listed, 'name'));
        self::assertSame(self::keySorted(BundledSchemas::TOOLS), self::keySorted($listed));

        foreach ($expected as $index => $tool) {
            self::assertIsArray($tool);
            self::assertSame(self::keySorted(array_diff_key($tool, ['outputSchema' => true])), self::keySorted(array_diff_key($listed[$index], ['outputSchema' => true])), "The tool {$tool['name']}.");
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
     * JSON object members in key order, so a comparison ignores the member order of the serializer.
     */
    private static function keySorted(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::keySorted(...), $value);
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
