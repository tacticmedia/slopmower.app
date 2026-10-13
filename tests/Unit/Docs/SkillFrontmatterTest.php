<?php

declare(strict_types=1);

namespace App\Tests\Unit\Docs;

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

        self::assertSame('slopmower', self::frontmatter()['name']);
        self::assertSame($installDirectory[1], self::frontmatter()['name']);
    }

    #[TestDox('description has at most 1,024 characters')]
    public function testDescriptionLength(): void
    {
        $description = self::frontmatter()['description'];

        self::assertIsString($description);
        self::assertNotSame('', $description);
        self::assertLessThanOrEqual(self::DESCRIPTION_MAX_CHARACTERS, mb_strlen($description));
    }

    #[TestDox('metadata has api v1')]
    public function testMetadataHasApiV1(): void
    {
        self::assertSame(['api' => 'v1'], self::frontmatter()['metadata']);
    }

    #[TestDox('The body has fewer than 500 lines')]
    public function testBodyLength(): void
    {
        self::assertLessThan(self::BODY_MAX_LINES, substr_count(self::parts()['body'], "\n"));
    }

    /**
     * @return array<string, mixed>
     */
    private static function frontmatter(): array
    {
        $frontmatter = Yaml::parse(self::parts()['frontmatter']);
        self::assertIsArray($frontmatter);

        return $frontmatter;
    }

    /**
     * @return array{frontmatter: string, body: string}
     */
    private static function parts(): array
    {
        $skill = (string) file_get_contents(dirname(__DIR__, 3).'/docs/user/skill/SKILL.md');
        self::assertSame(1, preg_match('/\A---\n(?<frontmatter>.*?)\n---\n(?<body>.*)\z/s', $skill, $parts));

        return ['frontmatter' => $parts['frontmatter'], 'body' => $parts['body']];
    }
}
