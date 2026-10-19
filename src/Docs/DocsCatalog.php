<?php

declare(strict_types=1);

namespace App\Docs;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/**
 * The files that /docs serves. A lookup takes the path from a listing of the directory, never from its input.
 */
final readonly class DocsCatalog
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/docs')]
        private string $docsDirectory,
    ) {
    }

    /**
     * @return list<string>
     */
    public function pageSlugs(): array
    {
        return array_keys($this->pagePaths());
    }

    public function pageMarkdown(string $slug): ?string
    {
        $path = $this->pagePaths()[$slug] ?? null;

        return null === $path ? null : self::read($path);
    }

    /**
     * The text of the first level-1 heading.
     */
    public static function title(string $markdown): ?string
    {
        return 1 === preg_match('/^# (.+)$/m', $markdown, $heading) ? trim($heading[1]) : null;
    }

    public function skillMarkdown(): string
    {
        return self::read($this->docsDirectory.'/user/skill/SKILL.md');
    }

    public function schemaPath(string $name): ?string
    {
        return self::listing($this->docsDirectory.'/specs/schemas', '.schema.json')[$name] ?? null;
    }

    /**
     * @return array<string, string> path by slug
     */
    private function pagePaths(): array
    {
        return self::listing($this->docsDirectory.'/user', '.md');
    }

    /**
     * @return array<string, string> path by file name without the suffix
     */
    private static function listing(string $directory, string $suffix): array
    {
        $paths = [];
        foreach ((new Finder())->files()->in($directory)->depth(0)->name('*'.$suffix)->sortByName() as $file) {
            $paths[$file->getBasename($suffix)] = $file->getPathname();
        }

        return $paths;
    }

    private static function read(string $path): string
    {
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(\sprintf('Cannot read %s.', $path));
        }

        return $content;
    }
}
