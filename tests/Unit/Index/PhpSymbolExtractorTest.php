<?php

declare(strict_types=1);

namespace App\Tests\Unit\Index;

use App\Index\ExtractedSymbol;
use App\Index\PhpSymbolExtractor;
use App\Index\SymbolTable;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PhpSymbolExtractorTest extends KernelTestCase
{
    private const string TOTALS = 'App\Billing\InvoiceTotals';

    #[TestDox('Fixtures with attributes, enums, property hooks, readonly classes, the pipe operator, and clone-with give the expected table')]
    public function testFixturesGiveTheExpectedTable(): void
    {
        $totals = self::TOTALS;

        self::assertSame([
            ['class', $totals, 'InvoiceTotals', null, 9, 37, 12, 9],
            ['class_constant', "{$totals}::BASIS_POINTS", 'BASIS_POINTS', $totals, 15, 15, 15, null],
            ['class_constant', "{$totals}::CENTS", 'CENTS', $totals, 15, 15, 15, null],
            ['method', "{$totals}::__construct()", '__construct', $totals, 17, 22, 17, null],
            ['property', "{$totals}::\$lines", 'lines', $totals, 18, 19, 19, 18],
            ['property', "{$totals}::\$taxBasisPoints", 'taxBasisPoints', $totals, 20, 20, 20, null],
            ['method', "{$totals}::total()", 'total', $totals, 24, 31, 27, 24],
            ['method', "{$totals}::withTax()", 'withTax', $totals, 33, 36, 33, null],
        ], self::rows(self::extract('InvoiceTotals.php')));

        self::assertSame([
            ['interface', 'App\Billing\HasNumber', 'HasNumber', null, 5, 10, 5, null],
            ['class_constant', 'App\Billing\HasNumber::PREFIX', 'PREFIX', 'App\Billing\HasNumber', 7, 7, 7, null],
            ['method', 'App\Billing\HasNumber::number()', 'number', 'App\Billing\HasNumber', 9, 9, 9, null],
            ['trait', 'App\Billing\Numbered', 'Numbered', null, 12, 15, 12, null],
            ['property', 'App\Billing\Numbered::$sequence', 'sequence', 'App\Billing\Numbered', 14, 14, 14, null],
            ['enum', 'App\Billing\Status', 'Status', null, 17, 26, 17, null],
            ['enum_case', 'App\Billing\Status::Draft', 'Draft', 'App\Billing\Status', 19, 19, 19, null],
            ['enum_case', 'App\Billing\Status::Paid', 'Paid', 'App\Billing\Status', 20, 20, 20, null],
            ['method', 'App\Billing\Status::label()', 'label', 'App\Billing\Status', 22, 25, 22, null],
            ['class', 'App\Billing\Invoice', 'Invoice', null, 28, 49, 28, null],
            ['property', 'App\Billing\Invoice::$customer', 'customer', 'App\Billing\Invoice', 32, 34, 32, null],
            ['property', 'App\Billing\Invoice::$a', 'a', 'App\Billing\Invoice', 36, 36, 36, null],
            ['property', 'App\Billing\Invoice::$b', 'b', 'App\Billing\Invoice', 36, 36, 36, null],
            ['method', 'App\Billing\Invoice::number()', 'number', 'App\Billing\Invoice', 38, 48, 38, null],
            ['function', 'App\Billing\invoice_total()', 'invoice_total', null, 51, 54, 51, null],
            ['constant', 'App\Billing\DEFAULT_CURRENCY', 'DEFAULT_CURRENCY', null, 56, 56, 56, null],
        ], self::rows(self::extract('Invoice.php')), 'The anonymous class and its method have no FQN.');
    }

    #[TestDox('The range starts at the doc comment and declarationStartLine includes attributes')]
    public function testRangeStartsAtTheDocComment(): void
    {
        $byFqn = array_column(self::rows(self::extract('InvoiceTotals.php')), null, 1);

        self::assertSame(['class', self::TOTALS, 'InvoiceTotals', null, 9, 37, 12, 9], $byFqn[self::TOTALS], 'The doc comment at 9, the attribute at 12, the class keyword at 13.');
        self::assertSame(['method', self::TOTALS.'::total()', 'total', self::TOTALS, 24, 31, 27, 24], $byFqn[self::TOTALS.'::total()']);
    }

    #[TestDox('A syntax error gives an empty list')]
    public function testSyntaxErrorGivesAnEmptyList(): void
    {
        $table = new PhpSymbolExtractor()->extract("<?php\n\nfinal class InvoiceTotals\n{\n    public function total(): int\n    {\n");

        self::assertSame(['php', true, []], [$table->language, $table->parseError, $table->symbols]);
    }

    #[TestDox('A duplicate use alias gives a parse error')]
    public function testDuplicateUseAliasGivesAParseError(): void
    {
        $table = new PhpSymbolExtractor()->extract("<?php\n\nnamespace App;\n\nuse App\\Money\\Amount;\nuse App\\Tax\\Amount;\n\nfinal class InvoiceTotals\n{\n}\n");

        self::assertSame(['php', true, []], [$table->language, $table->parseError, $table->symbols]);
    }

    private static function extract(string $fixture): SymbolTable
    {
        $table = new PhpSymbolExtractor()->extract((string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/php/{$fixture}"));
        self::assertSame(['php', false], [$table->language, $table->parseError]);

        return $table;
    }

    /**
     * @return list<array{string, string, string, string|null, int, int, int, int|null}> Kind, FQN, name, parent FQN,
     *                                                                                   range, declaration line, doc line
     */
    private static function rows(SymbolTable $table): array
    {
        return array_map(static fn (ExtractedSymbol $symbol): array => [
            $symbol->kind->value,
            $symbol->fqn,
            $symbol->name,
            $symbol->parentFqn,
            $symbol->range->startLine,
            $symbol->range->endLine,
            $symbol->declarationStartLine,
            $symbol->docStartLine,
        ], $table->symbols);
    }
}
