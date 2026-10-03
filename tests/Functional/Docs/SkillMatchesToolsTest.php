<?php

declare(strict_types=1);

namespace App\Tests\Functional\Docs;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Mcp\McpTestClient;
use App\Tests\Support\Specs\SpecsFiles;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Reads the "Tools" and "Errors" tables in docs/user/skill/SKILL.md.
 */
final class SkillMatchesToolsTest extends WebTestCase
{
    use ApiFixtures;
    use ResetDatabase;
    use SpecsFiles;

    #[TestDox('The tools named in the skill equal tools/list')]
    public function testToolsEqualToolsList(): void
    {
        $listedNames = array_column($this->listedTools(), 'name');
        sort($listedNames);
        $skillNames = array_keys(self::tableRows('## Tools'));
        sort($skillNames);
        self::assertSame($listedNames, $skillNames);
    }

    #[TestDox('The parameters of each tool in the skill equal its input schema, with ? on the optional ones')]
    public function testParametersEqualInputSchemas(): void
    {
        $skillParameters = array_map(
            static fn (string $cell): array => preg_match_all('/`([A-Za-z]+\??)`/', $cell, $spans) > 0 ? $spans[1] : [],
            self::tableRows('## Tools'),
        );

        foreach ($this->listedTools() as $tool) {
            $required = $tool['inputSchema']['required'] ?? [];
            $expected = array_map(static fn (string $name): string => \in_array($name, $required, true) ? $name : "{$name}?", array_keys($tool['inputSchema']['properties']));

            self::assertSame($expected, $skillParameters[$tool['name']] ?? null, $tool['name']);
        }
    }

    #[TestDox('Every error code in the skill exists in docs/specs')]
    public function testErrorCodesExistInDocsSpecs(): void
    {
        $documented = self::specsJson('schemas/common.schema.json')['$defs']['ErrorCode']['anyOf'][0]['enum'];
        self::assertIsArray($documented);

        $skillCodes = array_keys(self::tableRows('## Errors'));

        self::assertNotSame([], $skillCodes);
        self::assertSame([], array_values(array_diff($skillCodes, $documented)));
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

    /**
     * The rows of the table under a heading whose first cell is a code span.
     *
     * @return array<string, string> The second cell by the code span of the first cell
     */
    private static function tableRows(string $heading): array
    {
        $skill = (string) file_get_contents(dirname(__DIR__, 3).'/docs/user/skill/SKILL.md');
        self::assertSame(1, preg_match('/^'.preg_quote($heading, '/').'\n(?<section>.*?)(?=^## |\z)/ms', $skill, $match));
        preg_match_all('/^\| `(?<name>[a-z_]+)` \| (?<cell>[^|]*) \|/m', $match['section'], $rows);

        return array_combine($rows['name'], $rows['cell']);
    }
}
