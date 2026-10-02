<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

final class FileSizeLimitTest extends KernelTestCase
{
    private const int MAX_LINES = 800;

    /** Generated files, relative to the project root. */
    private const array EXEMPT_FILES = ['src/Mcp/BundledSchemas.php'];

    #[TestDox('No PHP, TypeScript, or CSS file in src, tests, or assets has more than 800 lines')]
    public function testNoSourceFileExceedsTheLineLimit(): void
    {
        $projectDir = dirname(__DIR__, 3);
        $directories = array_filter(
            ["{$projectDir}/src", "{$projectDir}/tests", "{$projectDir}/assets"],
            is_dir(...),
        );
        $lineCountsOfOversizedFiles = [];

        foreach (Finder::create()->files()->in($directories)->name(['*.php', '*.ts', '*.tsx', '*.css']) as $file) {
            $contents = $file->getContents();
            $lineCount = substr_count($contents, "\n") + ('' === $contents || str_ends_with($contents, "\n") ? 0 : 1);

            if ($lineCount > self::MAX_LINES) {
                $lineCountsOfOversizedFiles[substr($file->getPathname(), strlen($projectDir) + 1)] = $lineCount;
            }
        }

        self::assertSame([], array_diff_key($lineCountsOfOversizedFiles, array_flip(self::EXEMPT_FILES)));
    }
}
