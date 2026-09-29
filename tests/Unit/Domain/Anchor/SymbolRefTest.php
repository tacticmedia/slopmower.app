<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Anchor;

use App\Domain\Anchor\SymbolKind;
use App\Domain\Anchor\SymbolRef;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SymbolRefTest extends KernelTestCase
{
    use ViolatedFields;

    #[TestDox('Accepts a PHP symbol with an FQN of 1 to 1024 characters')]
    public function testAcceptsPhpSymbols(): void
    {
        $method = SymbolRef::php(SymbolKind::Method, 'App\Billing\InvoiceTotals::total()');
        $shortest = SymbolRef::php(SymbolKind::Constant, 'X');
        $longest = SymbolRef::fromParts('php', SymbolKind::Class_, str_repeat("\u{e9}", 1024));

        self::assertSame(['php', SymbolKind::Method, 'App\Billing\InvoiceTotals::total()'], [$method->language, $method->kind, $method->fqn]);
        self::assertSame('X', $shortest->fqn);
        self::assertSame(1024, mb_strlen($longest->fqn));
        self::assertTrue($method->equals(SymbolRef::fromParts('php', SymbolKind::Method, 'App\Billing\InvoiceTotals::total()')));
        self::assertFalse($method->equals(SymbolRef::php(SymbolKind::Function, 'App\Billing\InvoiceTotals::total()')));
    }

    /**
     * @param list<string> $expectedFields
     */
    #[DataProvider('invalidSymbols')]
    #[TestDox('Rejects a language other than php and an empty or too long FQN')]
    public function testRejectsInvalidSymbols(string $language, string $fqn, array $expectedFields): void
    {
        self::assertSame($expectedFields, self::violatedFields(static fn (): SymbolRef => SymbolRef::fromParts($language, SymbolKind::Method, $fqn)));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function invalidSymbols(): iterable
    {
        yield 'other language' => ['typescript', 'total', ['symbol.language']];
        yield 'empty FQN' => ['php', '', ['symbol.fqn']];
        yield 'FQN of 1025 multibyte characters' => ['php', str_repeat("\u{e9}", 1025), ['symbol.fqn']];
    }
}
