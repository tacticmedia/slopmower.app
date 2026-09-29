<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Anchor;

use App\Domain\Anchor\LineRange;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LineRangeTest extends KernelTestCase
{
    use ViolatedFields;

    #[TestDox('Accepts a single line and a range of several lines')]
    public function testAcceptsValidRanges(): void
    {
        $single = LineRange::singleLine(1);
        $several = LineRange::of(38, 49);

        self::assertSame([1, 1, 1, true], [$single->startLine, $single->endLine, $single->lineCount(), $single->isSingleLine()]);
        self::assertSame([38, 49, 12, false], [$several->startLine, $several->endLine, $several->lineCount(), $several->isSingleLine()]);
        self::assertTrue($several->equals(LineRange::of(38, 49)));
        self::assertFalse($several->equals(LineRange::of(38, 50)));
    }

    /**
     * @param list<string> $expectedFields
     */
    #[DataProvider('invalidRanges')]
    #[TestDox('Rejects a start line below 1 and an end line before the start line')]
    public function testRejectsInvalidRanges(int $startLine, int $endLine, array $expectedFields): void
    {
        self::assertSame($expectedFields, self::violatedFields(static fn (): LineRange => LineRange::of($startLine, $endLine, 'anchor.range')));
    }

    /**
     * @return iterable<string, array{int, int, list<string>}>
     */
    public static function invalidRanges(): iterable
    {
        yield 'start line 0' => [0, 3, ['anchor.range.startLine']];
        yield 'end line before start line' => [5, 4, ['anchor.range.endLine']];
        yield 'both lines 0' => [0, 0, ['anchor.range.startLine', 'anchor.range.endLine']];
    }
}
