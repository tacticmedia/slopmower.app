<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Anchor;

use App\Domain\Anchor\ContentSnapshot;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @phpstan-type PartOverrides array{lines?: list<string>, truncated?: bool, lineCount?: int, before?: list<string>, after?: list<string>, normalizedHash?: string}
 */
final class ContentSnapshotTest extends KernelTestCase
{
    use ViolatedFields;

    private const string HASH = 'sha256:09938bff856dc45843179cd6426abee27580709e3ea2c414914ed5915d64787c';

    #[TestDox('Accepts the snapshot of a whole region and of a truncated region')]
    public function testAcceptsWholeAndTruncatedRegions(): void
    {
        $whole = ContentSnapshot::fromParts(['    {', '', '    }'], false, 3, ['    }', '', ''], ['', '    {'], self::HASH);
        $truncated = ContentSnapshot::fromParts(self::lines(200), true, 201, [], [], self::HASH);

        self::assertSame([['    {', '', '    }'], false, 3], [$whole->lines, $whole->truncated, $whole->lineCount]);
        self::assertSame([200, true, 201], [\count($truncated->lines), $truncated->truncated, $truncated->lineCount]);
        self::assertTrue($whole->equals(ContentSnapshot::fromParts(['    {', '', '    }'], false, 3, ['    }', '', ''], ['', '    {'], self::HASH)));
        self::assertFalse($whole->equals(ContentSnapshot::fromParts(['    {', '', '    }'], false, 3, ['    }', ''], ['', '    {'], self::HASH)));
    }

    /**
     * @param PartOverrides $overrides
     * @param list<string>  $expectedFields
     */
    #[DataProvider('linesThatDisagree')]
    #[TestDox('Rejects lines that disagree with truncated and lineCount')]
    public function testRejectsLinesThatDisagreeWithTruncatedAndLineCount(array $overrides, array $expectedFields): void
    {
        self::assertSame($expectedFields, self::violatedFields(static fn (): ContentSnapshot => self::fromOverrides($overrides)));
    }

    /**
     * @return iterable<string, array{PartOverrides, list<string>}>
     */
    public static function linesThatDisagree(): iterable
    {
        yield 'lineCount 0' => [['lines' => [], 'lineCount' => 0], ['snapshot.lineCount']];
        yield 'fewer lines than lineCount' => [['lines' => ['a'], 'lineCount' => 2], ['snapshot.lines']];
        yield '201 lines, not truncated' => [['lines' => self::lines(201), 'lineCount' => 201], ['snapshot.lines']];
        yield 'truncated region of 200 lines' => [['lines' => self::lines(200), 'truncated' => true, 'lineCount' => 200], ['snapshot.lines']];
        yield 'truncated with 199 lines' => [['lines' => self::lines(199), 'truncated' => true, 'lineCount' => 300], ['snapshot.lines']];
    }

    /**
     * @param PartOverrides $overrides
     * @param list<string>  $expectedFields
     */
    #[DataProvider('malformedParts')]
    #[TestDox('Rejects more than 3 context lines, unnormalized lines, and a malformed hash')]
    public function testRejectsMalformedParts(array $overrides, array $expectedFields): void
    {
        self::assertSame($expectedFields, self::violatedFields(static fn (): ContentSnapshot => self::fromOverrides($overrides)));
    }

    /**
     * @return iterable<string, array{PartOverrides, list<string>}>
     */
    public static function malformedParts(): iterable
    {
        yield '4 lines before' => [['before' => ['a', 'b', 'c', 'd']], ['snapshot.before']];
        yield '4 lines after' => [['after' => ['a', 'b', 'c', 'd']], ['snapshot.after']];
        yield 'trailing space' => [['lines' => ['a ']], ['snapshot.lines']];
        yield 'CR before' => [['before' => ["a\r"]], ['snapshot.before']];
        yield 'LF inside a line after' => [['after' => ["a\nb"]], ['snapshot.after']];
        yield 'hash without prefix' => [['normalizedHash' => substr(self::HASH, 7)], ['snapshot.normalizedHash']];
        yield 'upper-case hash' => [['normalizedHash' => strtoupper(self::HASH)], ['snapshot.normalizedHash']];
        yield 'hash with a trailing LF' => [['normalizedHash' => self::HASH."\n"], ['snapshot.normalizedHash']];
        yield 'invalid UTF-8 in lines' => [['lines' => ["caf\xE9"]], ['snapshot.lines']];
        yield 'NUL after' => [['after' => ["a\0b"]], ['snapshot.after']];
    }

    /**
     * @param PartOverrides $overrides
     */
    private static function fromOverrides(array $overrides): ContentSnapshot
    {
        $parts = $overrides + ['lines' => ['a'], 'truncated' => false, 'lineCount' => 1, 'before' => [], 'after' => [], 'normalizedHash' => self::HASH];

        return ContentSnapshot::fromParts($parts['lines'], $parts['truncated'], $parts['lineCount'], $parts['before'], $parts['after'], $parts['normalizedHash']);
    }

    /**
     * @return list<string>
     */
    private static function lines(int $count): array
    {
        return array_map(static fn (int $number): string => 'line '.$number, range(1, $count));
    }
}
