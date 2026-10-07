<?php

declare(strict_types=1);

namespace App\Tests\Functional\Index;

use App\Index\ExtractedSymbol;
use App\Index\TreeSitterSymbolExtractor;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Against the `symbols` service of the compose stack.
 */
final class TreeSitterSymbolExtractorTest extends KernelTestCase
{
    private const string TOTALS = <<<'TS'
        /**
         * Sums invoice lines.
         */
        export class InvoiceTotals {
            total(): number {
                return 0;
            }
        }

        TS;

    #[TestDox('A TypeScript file round-trips through the service')]
    public function testTypeScriptRoundTrip(): void
    {
        $table = self::getContainer()->get(TreeSitterSymbolExtractor::class)->extract('typescript', self::TOTALS);

        self::assertSame(['typescript', false], [$table->language, $table->parseError]);
        self::assertSame([
            ['class', 'InvoiceTotals', 'InvoiceTotals', null, 1, 8, 4, 1],
            ['method', 'InvoiceTotals.total()', 'total', 'InvoiceTotals', 5, 7, 5, null],
        ], array_map(static fn (ExtractedSymbol $symbol): array => [
            $symbol->kind->value, $symbol->fqn, $symbol->name, $symbol->parentFqn, $symbol->range->startLine, $symbol->range->endLine, $symbol->declarationStartLine, $symbol->docStartLine,
        ], $table->symbols));
    }

    #[TestDox('A syntax error gives a parse error and no symbols')]
    public function testSyntaxErrorGivesAParseError(): void
    {
        $table = self::getContainer()->get(TreeSitterSymbolExtractor::class)->extract('typescript', "export class InvoiceTotals {\n    total(): number {\n");

        self::assertSame(['typescript', true, []], [$table->language, $table->parseError, $table->symbols]);
    }
}
