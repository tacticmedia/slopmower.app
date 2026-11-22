<?php

declare(strict_types=1);

namespace App\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The Agent Skills format: https://agentskills.io/specification.
 */
final class SkillFrontmatterTest extends KernelTestCase
{
    private const int DESCRIPTION_MAX_CHARACTERS = 1024;
    private const int BODY_MAX_LINES = 500;

    #[TestDox('name is slopmower, the name of the install directory')]
    public function testNameIsTheInstallDirectory(): void
    {
        $specification = (string) file_get_contents(dirname(__DIR__, 3).'/docs/specs/rest-api-requests.md');
        self::assertSame(1, preg_match('#--create-dirs -o \.claude/skills/([^/]+)/SKILL\.md`#', $specification, $installDirectory));

        self::assertSame('slopmower', self::frontmatter('docs/user/skill/SKILL.md')['name']);
        self::assertSame($installDirectory[1], self::frontmatter('docs/user/skill/SKILL.md')['name']);
    }

    #[TestDox('name is the directory of the skill under .claude/skills')]
    #[DataProvider('skills')]
    public function testNameIsTheSkillDirectory(string $name, string $path): void
    {
        self::assertSame($name, self::frontmatter($path)['name']);
        self::assertFileExists(dirname(__DIR__, 3)."/.claude/skills/{$name}/SKILL.md");
    }

    #[TestDox('description has at most 1,024 characters')]
    #[DataProvider('skills')]
    public function testDescriptionLength(string $name, string $path): void
    {
        $description = self::frontmatter($path)['description'];

        self::assertIsString($description);
        self::assertNotSame('', $description);
        self::assertLessThanOrEqual(self::DESCRIPTION_MAX_CHARACTERS, mb_strlen($description));
    }

    #[TestDox('metadata has api v1')]
    #[DataProvider('skills')]
    public function testMetadataHasApiV1(string $name, string $path): void
    {
        self::assertSame(['api' => 'v1'], self::frontmatter($path)['metadata']);
    }

    #[TestDox('The body has fewer than 500 lines')]
    #[DataProvider('skills')]
    public function testBodyLength(string $name, string $path): void
    {
        self::assertLessThan(self::BODY_MAX_LINES, substr_count(self::parts($path)['body'], "\n"));
    }

    /**
     * @return iterable<string, array{string, string}> The name and the source of each skill
     */
    public static function skills(): iterable
    {
        yield 'slopmower' => ['slopmower', 'docs/user/skill/SKILL.md'];
        yield 'slopmower-issues' => ['slopmower-issues', 'docs/user/skill-issues/SKILL.md'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function frontmatter(string $path): array
    {
        $frontmatter = Yaml::parse(self::parts($path)['frontmatter']);
        self::assertIsArray($frontmatter);

        return $frontmatter;
    }

    /**
     * @return array{frontmatter: string, body: string}
     */
    private static function parts(string $path): array
    {
        $skill = (string) file_get_contents(dirname(__DIR__, 3)."/{$path}");
        self::assertSame(1, preg_match('/\A---\n(?<frontmatter>.*?)\n---\n(?<body>.*)\z/s', $skill, $parts));

        return ['frontmatter' => $parts['frontmatter'], 'body' => $parts['body']];
    }
}
