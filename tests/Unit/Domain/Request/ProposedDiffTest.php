<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Request\ProposedDiff;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProposedDiffTest extends KernelTestCase
{
    use InvalidInputAssertions;

    #[TestDox('Accepts a git diff of 9 files and keeps the text as written')]
    public function testAcceptsNineFiles(): void
    {
        $diff = self::files(9);

        self::assertSame($diff, ProposedDiff::fromString($diff)->toString());
    }

    #[TestDox('Rejects $case')]
    #[DataProvider('invalidDiffs')]
    public function testRejectsInvalidDiffs(string $case, string $diff): void
    {
        self::assertInvalidInput(['diff'], static fn () => ProposedDiff::fromString($diff));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDiffs(): iterable
    {
        yield '10 files' => ['10 files', self::files(10)];
        yield 'no diff --git header' => ['a diff without a "diff --git" line', "--- a/README.md\n+++ b/README.md\n@@ -1 +1 @@\n-Old\n+New\n"];
        yield 'more than 65,536 characters' => ['more than 65,536 characters', self::files(1).'+'.str_repeat('a', 65536)."\n"];
    }

    private static function files(int $count): string
    {
        $diff = '';

        for ($file = 1; $file <= $count; ++$file) {
            $diff .= "diff --git a/src/File{$file}.php b/src/File{$file}.php\n--- a/src/File{$file}.php\n+++ b/src/File{$file}.php\n@@ -1 +1 @@\n-old\n+new\n";
        }

        return $diff;
    }
}
