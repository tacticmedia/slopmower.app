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
 * Reads the "Tools" and "Errors" tables of the two skills: `slopmower` for the requests, `slopmower-issues` for the
 * issue tracker. Each tool is in one of them.
 */
final class SkillMatchesToolsTest extends WebTestCase
{
    use ApiFixtures;
    use ResetDatabase;
    use SpecsFiles;

    /** The source of each skill by its name, which is its directory under .claude/skills. */
    private const array SKILLS = ['slopmower' => 'docs/user/skill/SKILL.md', 'slopmower-issues' => 'docs/user/skill-issues/SKILL.md'];

    #[TestDox('The tools named in the two skills together equal tools/list')]
    public function testToolsEqualToolsList(): void
    {
        $listedNames = array_column($this->listedTools(), 'name');
        sort($listedNames);
        $skillNames = array_merge(...array_values(array_map(static fn (string $path): array => array_keys(self::tableRows($path, '## Tools')), self::SKILLS)));
        sort($skillNames);
        self::assertSame($listedNames, $skillNames);
    }

    #[TestDox('The parameters of each tool in the skill equal its input schema, with ? on the optional ones')]
    public function testParametersEqualInputSchemas(): void
    {
        $skillParameters = array_map(
            static fn (string $cell): array => preg_match_all('/`([A-Za-z]+\??)`/', $cell, $spans) > 0 ? $spans[1] : [],
            array_merge(...array_values(array_map(static fn (string $path): array => self::tableRows($path, '## Tools'), self::SKILLS))),
        );

        foreach ($this->listedTools() as $tool) {
            $required = $tool['inputSchema']['required'] ?? [];
            $expected = array_map(static fn (string $name): string => \in_array($name, $required, true) ? $name : "{$name}?", array_keys($tool['inputSchema']['properties']));

            self::assertSame($expected, $skillParameters[$tool['name']] ?? null, $tool['name']);
        }
    }

    #[TestDox('Every error code in each skill exists in docs/specs')]
    public function testErrorCodesExistInDocsSpecs(): void
    {
        $documented = self::specsJson('schemas/common.schema.json')['$defs']['ErrorCode']['anyOf'][0]['enum'];
        self::assertIsArray($documented);

        foreach (self::SKILLS as $path) {
            $skillCodes = array_keys(self::tableRows($path, '## Errors'));

            self::assertNotSame([], $skillCodes, $path);
            self::assertSame([], array_values(array_diff($skillCodes, $documented)), $path);
        }
    }

    #[TestDox('Each skill of this repository equals its source in docs/user')]
    public function testProjectSkillsEqualThePublicSkills(): void
    {
        foreach (self::SKILLS as $name => $path) {
            self::assertSame(self::skill($path), (string) file_get_contents(dirname(__DIR__, 3)."/.claude/skills/{$name}/SKILL.md"), $name);
        }
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
    private static function tableRows(string $path, string $heading): array
    {
        self::assertSame(1, preg_match('/^'.preg_quote($heading, '/').'\n(?<section>.*?)(?=^## |\z)/ms', self::skill($path), $match));
        preg_match_all('/^\| `(?<name>[a-z_]+)` \| (?<cell>[^|]*) \|/m', $match['section'], $rows);

        return array_combine($rows['name'], $rows['cell']);
    }

    private static function skill(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3)."/{$path}");
    }
}
