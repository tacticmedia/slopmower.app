<?php

declare(strict_types=1);

namespace App\Tests\Support\Specs;

trait SpecsFiles
{
    /**
     * @param string $path Relative to docs/specs, for example "schemas/common.schema.json"
     *
     * @return array<mixed>
     */
    private static function specsJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/docs/specs/'.$path), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * The first fenced code block under a heading, without the fences.
     *
     * @param string $path    Relative to docs/specs, for example "mcp.md"
     * @param string $heading The full heading line, for example "## 2. Server instructions"
     */
    private static function specsCodeBlock(string $path, string $heading): string
    {
        $markdown = (string) file_get_contents(dirname(__DIR__, 3).'/docs/specs/'.$path);
        $pattern = '/^'.preg_quote($heading, '/').'\n.*?^```[a-z]*\n(.*?)\n```$/ms';
        self::assertSame(1, preg_match($pattern, $markdown, $match), "No code block under {$heading} in {$path}.");

        return $match[1];
    }
}
