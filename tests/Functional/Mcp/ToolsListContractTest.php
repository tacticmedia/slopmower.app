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
        'followUps' => 'common.schema.json#/$defs/NoNulText',
        'group' => 'common.schema.json#/$defs/NoNulText',
        'id' => 'common.schema.json#/$defs/Ulid',
        'issue' => 'common.schema.json#/$defs/IssueKey',
        'note' => 'common.schema.json#/$defs/NoNulText',
        'path' => 'common.schema.json#/$defs/NoNulText',
        'plan' => 'common.schema.json#/$defs/NoNulText',
        'planRevision' => 'common.schema.json#/$defs/Ulid',
        'problem' => 'common.schema.json#/$defs/NoNulText',
        'project' => 'common.schema.json#/$defs/ProjectKey',
        'reason' => 'common.schema.json#/$defs/NoNulText',
        'ref' => 'common.schema.json#/$defs/NoNulText',
        'replaces' => 'common.schema.json#/$defs/Ulid',
        'repository' => 'common.schema.json#/$defs/NoNulText',
        'requestId' => 'common.schema.json#/$defs/Ulid',
        'requestIds' => 'common.schema.json#/$defs/Ulid',
        'result' => 'common.schema.json#/$defs/NoNulText',
        'review' => 'common.schema.json#/$defs/Ulid',
        'reviewNote' => 'common.schema.json#/$defs/NoNulText',
        'reviewer' => 'common.schema.json#/$defs/NoNulText',
        'session' => 'common.schema.json#/$defs/SessionName',
        'symbol' => 'common.schema.json#/$defs/NoNulText',
        'target' => 'common.schema.json#/$defs/IssueKey',
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

    #[TestDox('No output schema of tools/list has a closed set: each enum is a condition under if, or a member of an anyOf with any string')]
    public function testOutputSchemasHaveNoClosedSet(): void
    {
        $closed = [];
        $open = 0;

        foreach ($this->listedTools() as $tool) {
            self::collectSets($tool['outputSchema'] ?? null, $tool['name'], $closed, $open);
        }

        self::assertSame([], $closed);
        self::assertGreaterThan(0, $open);
    }

    /**
     * @param list<string> $closed The paths of the closed sets
     */
    private static function collectSets(mixed $node, string $path, array &$closed, int &$open, bool $openSetMember = false): void
    {
        if (!\is_array($node)) {
            return;
        }

        if (isset($node['enum']) && $openSetMember) {
            ++$open;
        } elseif (isset($node['enum'])) {
            $closed[] = $path;
        }

        $isOpenSet = \is_array($node['anyOf'] ?? null) && \in_array(['type' => 'string'], $node['anyOf'], true);

        foreach ($node as $key => $value) {
            if ('if' === $key) {
                continue;
            }

            if ('anyOf' === $key && $isOpenSet && \is_array($value)) {
                foreach ($value as $index => $member) {
                    self::collectSets($member, "{$path}/anyOf/{$index}", $closed, $open, true);
                }

                continue;
            }

            self::collectSets($value, "{$path}/{$key}", $closed, $open);
        }
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
