<?php

declare(strict_types=1);

namespace App\Tests\Unit\Index;

use App\Domain\Anchor\SymbolKind;
use App\Domain\Anchor\SymbolRef;
use App\Index\ExtractedSymbol;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExtractedSymbolTest extends KernelTestCase
{
    #[TestDox('The row guard refuses text that is not UTF-8, holds a NUL, or exceeds the column, and a name outside the FQN')]
    public function testRowGuardRefusesRowsThatCannotBeStored(): void
    {
        $tooLong = str_repeat('é', SymbolRef::MAX_FQN_CHARACTERS + 1);
        $row = static fn (string $fqn, string $name, ?string $parentFqn = null): ?ExtractedSymbol => ExtractedSymbol::storable(SymbolKind::Method, $fqn, $name, $parentFqn, 1, 2, 1, null);

        self::assertNotNull($row('Invoice.total()', 'total', 'Invoice'));
        self::assertSame([null, null, null, null, null], [
            $row("Invoice.\xFF()", "\xFF"),
            $row("Invoice.to\0tal()", "to\0tal"),
            $row("{$tooLong}()", $tooLong),
            $row('Invoice.total()', 'total', $tooLong),
            $row('Invoice.total()', 'sum'),
        ]);
    }
}
