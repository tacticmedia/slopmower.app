<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Anchor;

use App\Domain\Anchor\SymbolKind;
use App\Domain\Anchor\SymbolRef;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SymbolRefTest extends KernelTestCase
{
    use InvalidInputAssertions;

    #[TestDox('Accepts a PHP method FQN of 1024 characters')]
    public function testAcceptsAnFqn(): void
    {
        $fqn = 'App\\Billing\\InvoiceTotals::'.str_repeat('a', 995).'()';

        self::assertSame($fqn, SymbolRef::of('php', SymbolKind::Method, $fqn)->fqn);
    }

    #[TestDox('Rejects an empty language and an FQN over 1024 characters')]
    public function testRejectsInvalidSymbols(): void
    {
        self::assertInvalidInput(
            ['symbol.language', 'symbol.fqn'],
            static fn () => SymbolRef::of('', SymbolKind::Function, str_repeat('a', 1025)),
        );
    }
}
