<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Git;

use App\Domain\Error\InvalidInput;
use App\Domain\Git\RepoPath;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RepoPathTest extends KernelTestCase
{
    use ViolatedFields;

    /**
     * @return iterable<string, array{string}>
     */
    public static function escapingPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'absolute' => ['/etc/passwd'];
        yield 'dot dot' => ['..'];
        yield 'leading dot segment' => ['./a'];
        yield 'inner dot dot segment' => ['a/../b'];
        yield 'trailing dot segment' => ['a/.'];
        yield 'dot dot segment after a line break' => ["a\n/../b"];
        yield 'empty inner segment' => ['a//b'];
        yield 'trailing slash' => ['a/'];
        yield 'leading dash' => ['-a'];
        yield 'NUL byte' => ["a\0b"];
        yield 'trailing NUL byte' => ["a\0"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unicodePaths(): iterable
    {
        yield 'Latin' => ["docs/caf\u{00E9}/r\u{00E9}sum\u{00E9}.md"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatLookLikeDotSegments(): iterable
    {
        yield 'dotfile' => ['.gitignore'];
        yield 'three dots' => ['...'];
        yield 'dash after a slash' => ['a/-b'];
    }

    #[DataProvider('escapingPaths')]
    #[TestDox('Rejects absolute paths, dot segments, empty segments, a leading dash, and NUL')]
    public function testRejectsPathsThatEscapeTheTree(string $value): void
    {
        $this->expectException(InvalidInput::class);

        RepoPath::fromString($value);
    }

    #[DataProvider('unicodePaths')]
    #[TestDox('Accepts Unicode names')]
    public function testAcceptsUnicodeNames(string $value): void
    {
        $path = RepoPath::fromString($value);

        self::assertSame($value, $path->toString());
        self::assertSame($value, (string) $path);
        self::assertTrue($path->equals(RepoPath::fromString($value)));
    }

    #[DataProvider('namesThatLookLikeDotSegments')]
    #[TestDox('Accepts dots and dashes that do not form a dot segment or a leading dash')]
    public function testAcceptsNamesThatLookLikeDotSegments(string $value): void
    {
        self::assertSame($value, RepoPath::fromString($value)->toString());
    }

    #[TestDox('Accepts 4096 characters, counted as characters and not bytes')]
    public function testAccepts4096Characters(): void
    {
        $value = str_repeat("\u{00E9}", 4096);

        self::assertSame($value, RepoPath::fromString($value)->toString());
    }

    #[TestDox('Rejects 4097 characters')]
    public function testRejects4097Characters(): void
    {
        $this->expectException(InvalidInput::class);

        RepoPath::fromString(str_repeat("\u{00E9}", 4097));
    }

    #[TestDox('Rejects invalid UTF-8')]
    public function testRejectsInvalidUtf8(): void
    {
        $this->expectException(InvalidInput::class);

        RepoPath::fromString("src/\xff\xfe.php");
    }

    #[TestDox('Equals only the same path, case-sensitively')]
    public function testEqualsOnlyTheSamePath(): void
    {
        self::assertFalse(RepoPath::fromString('src/A.php')->equals(RepoPath::fromString('src/a.php')));
    }

    #[TestDox('Names path or the given field in the violation')]
    public function testNamesPathOrTheGivenFieldInTheViolation(): void
    {
        self::assertSame(['path'], self::violatedFields(static fn (): RepoPath => RepoPath::fromString('/')));
        self::assertSame(['anchor.path'], self::violatedFields(static fn (): RepoPath => RepoPath::fromString('/', 'anchor.path')));
    }
}
