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
}
