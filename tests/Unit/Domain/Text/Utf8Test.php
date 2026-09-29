<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Text;

use App\Domain\Text\Utf8;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class Utf8Test extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenTexts(): iterable
    {
        yield 'Latin-1 byte' => ["Caf\xE9 totals", "Caf\u{FFFD} totals"];
        yield 'NUL' => ["a\0b", "a\u{FFFD}b"];
    }

    #[DataProvider('brokenTexts')]
    #[TestDox('Replaces each invalid UTF-8 sequence and each NUL with U+FFFD')]
    public function testReplacesInvalidSequencesAndNul(string $text, string $expected): void
    {
        self::assertSame($expected, Utf8::scrub($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validTexts(): iterable
    {
        yield 'multi-byte and astral' => ["\u{c5} e\u{0301} \u{1F642}"];
    }

    #[DataProvider('validTexts')]
    #[TestDox('Keeps valid UTF-8 without NUL unchanged')]
    public function testKeepsValidUtf8Unchanged(string $text): void
    {
        self::assertSame($text, Utf8::scrub($text));
    }
}
