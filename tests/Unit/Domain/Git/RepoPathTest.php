<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Git;

use App\Domain\Git\RepoPath;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RepoPathTest extends KernelTestCase
{
    use InvalidInputAssertions;

    #[TestDox('Rejects absolute paths, dot segments, empty segments, a leading dash, and NUL')]
    #[DataProvider('invalidPaths')]
    public function testRejectsPathsThatEscapeTheTree(string $value): void
    {
        self::assertInvalidInput(['path'], static fn () => RepoPath::fromString($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'absolute' => ['/etc/passwd'];
        yield 'leading ..' => ['../secrets.php'];
        yield 'leading .' => ['./src/Kernel.php'];
        yield 'empty segment' => ['src//Kernel.php'];
        yield 'empty' => [''];
        yield 'leading dash' => ['-rf'];
        yield 'NUL' => ["src/Kernel.php\0.txt"];
        yield 'invalid UTF-8' => ["src/\xC3\x28.php"];
        yield '4097 characters' => [str_repeat('a', 4097)];
    }

    #[TestDox('Accepts Unicode names')]
    #[DataProvider('unicodePaths')]
    public function testAcceptsUnicodeNames(string $value): void
    {
        self::assertSame($value, RepoPath::fromString($value)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unicodePaths(): iterable
    {
        yield 'accents' => ['docs/naïve café.md'];
        yield '4096 multi-byte characters' => [str_repeat('é', 4096)];
    }

    #[TestDox('Accepts dot files, inner dots, and a dash after the first character')]
    public function testAcceptsDotFilesAndInnerDashes(): void
    {
        foreach (['.github/workflows/ci.yml', 'a..b/c', 'bin/-x', 'x-'] as $value) {
            self::assertSame($value, RepoPath::fromString($value)->toString());
        }
    }
}
