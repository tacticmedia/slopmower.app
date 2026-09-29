<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FileSizeLimitTest extends KernelTestCase
{
    private const int MAX_LINES = 800;

    /**
     * Paths relative to the project root.
     *
     * @var list<string>
     */
    private const array GENERATED_FILES = [];

    #[TestDox('No PHP, TypeScript, or CSS file in src, tests, or assets has more than 800 lines')]
    public function testNoFileExceedsTheLineLimit(): void
    {
        $lineCountsByPath = [];
        foreach (ProjectFiles::find(['src', 'tests', 'assets'], ['*.php', '*.ts', '*.tsx', '*.css']) as $path => $file) {
            $lineCount = self::lineCount($file->getContents());
            if ($lineCount > self::MAX_LINES) {
                $lineCountsByPath[$path] = $lineCount;
            }
        }

        self::assertSame([], array_diff_key($lineCountsByPath, array_flip(self::GENERATED_FILES)));
    }

    private static function lineCount(string $contents): int
    {
        return substr_count($contents, "\n") + (str_ends_with($contents, "\n") ? 0 : 1);
    }
}
