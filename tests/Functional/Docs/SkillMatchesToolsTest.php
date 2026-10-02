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
 * Reads the first column of the "Tools" and "Errors" tables in docs/user/skill/SKILL.md.
 */
final class SkillMatchesToolsTest extends WebTestCase
{
    use ApiFixtures;
    use ResetDatabase;
    use SpecsFiles;

    #[TestDox('The tools named in the skill equal tools/list')]
    public function testToolsEqualToolsList(): void
    {
        $client = static::createClient();
        [, $secret] = self::storeToken(TenantFixtures::of(self::getContainer())->create());
        $mcp = new McpTestClient($client, $secret);
        $mcp->initialize();

        $listed = McpTestClient::result($mcp->request('tools/list'))['tools'];

        self::assertIsArray($listed);
        $listedNames = array_column($listed, 'name');
        sort($listedNames);
        $skillNames = self::firstColumn('## Tools');
        sort($skillNames);
        self::assertSame($listedNames, $skillNames);
    }

    #[TestDox('Every error code in the skill exists in docs/specs')]
    public function testErrorCodesExistInDocsSpecs(): void
    {
        $documented = self::specsJson('schemas/common.schema.json')['$defs']['ErrorCode']['anyOf'][0]['enum'];
        self::assertIsArray($documented);

        $skillCodes = self::firstColumn('## Errors');

        self::assertNotSame([], $skillCodes);
        self::assertSame([], array_values(array_diff($skillCodes, $documented)));
    }

    /**
     * The code spans in the first column of the table under a heading.
     *
     * @return list<string>
     */
    private static function firstColumn(string $heading): array
    {
        $skill = (string) file_get_contents(dirname(__DIR__, 3).'/docs/user/skill/SKILL.md');
        self::assertSame(1, preg_match('/^'.preg_quote($heading, '/').'\n(?<section>.*?)(?=^## |\z)/ms', $skill, $match));
        preg_match_all('/^\| `(?<name>[a-z_]+)` \|/m', $match['section'], $rows);

        return $rows['name'];
    }
}
