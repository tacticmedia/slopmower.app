<?php

declare(strict_types=1);

namespace App\Tests\Unit\Index;

use App\Index\PhpSymbolExtractor;
use App\Index\Reference;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * {@see PhpSymbolExtractor::referencesAt()} and {@see PhpSymbolExtractor::referencesNamed()}.
 */
final class PhpReferencesTest extends KernelTestCase
{
    private const string SOURCE = <<<'PHP'
        <?php

        namespace App\Billing;

        use App\Billing\Tax\TaxRate;

        final class InvoiceTotals
        {
            public function total(int $net): int
            {
                $rate = new TaxRate();
                $label = 'Größe '.TaxRate::LABEL;
                $rounded = TaxRounding::round($net, $rate);
                $this->ledger->apply($rounded);

                return round_half_up($rounded);
            }
        }
        PHP;

    #[TestDox('A class name in new resolves through the use import')]
    public function testClassNameInNew(): void
    {
        self::assertEquals([Reference::classLike('App\Billing\Tax\TaxRate')], self::referencesAt('new TaxRate', 'TaxRate'));
    }

    #[TestDox('The column counts characters, not bytes')]
    public function testColumnCountsCharacters(): void
    {
        self::assertEquals([Reference::classLike('App\Billing\Tax\TaxRate')], self::referencesAt("'Größe '.TaxRate::", 'TaxRate'));
    }

    #[TestDox('The method of a static call resolves with its class')]
    public function testStaticMethodCall(): void
    {
        self::assertEquals([Reference::method('App\Billing\TaxRounding', 'round')], self::referencesAt('TaxRounding::round', 'round'));
    }

    #[TestDox('An unqualified function call falls back from the namespace to the global function')]
    public function testFunctionCallWithFallback(): void
    {
        self::assertEquals([Reference::function('App\Billing\round_half_up', 'round_half_up')], self::referencesAt('round_half_up(', 'round_half_up'));
    }

    #[TestDox('A method call on a variable gives no reference')]
    public function testMethodCallOnVariable(): void
    {
        self::assertSame([], self::referencesAt('->apply(', 'apply'));
    }

    #[TestDox('A typed short name resolves through the use import')]
    public function testShortNameThroughUseImport(): void
    {
        self::assertEquals([
            Reference::classLike('App\Billing\Tax\TaxRate'),
            Reference::function('App\Billing\TaxRate', 'TaxRate'),
            Reference::constant('App\Billing\TaxRate', 'TaxRate'),
        ], new PhpSymbolExtractor()->referencesNamed(self::SOURCE, 'TaxRate'));
    }

    #[TestDox('A typed name with a leading backslash is fully qualified')]
    public function testFullyQualifiedName(): void
    {
        self::assertEquals([
            Reference::classLike('App\Money\Money'),
            Reference::function('App\Money\Money'),
            Reference::constant('App\Money\Money'),
        ], new PhpSymbolExtractor()->referencesNamed(self::SOURCE, '\App\Money\Money'));
    }

    #[TestDox('A typed name with namespace\ resolves in the namespace of the file')]
    public function testRelativeName(): void
    {
        self::assertEquals([
            Reference::classLike('App\Billing\TaxRounding'),
            Reference::function('App\Billing\TaxRounding'),
            Reference::constant('App\Billing\TaxRounding'),
        ], new PhpSymbolExtractor()->referencesNamed(self::SOURCE, 'Namespace\TaxRounding'));
    }

    /**
     * @param string $code A unique part of a line of SOURCE that contains $name
     *
     * @return list<Reference> The references at the first character of $name
     */
    private static function referencesAt(string $code, string $name): array
    {
        foreach (explode("\n", self::SOURCE) as $index => $text) {
            $start = mb_strpos($text, $code);

            if (false !== $start) {
                return new PhpSymbolExtractor()->referencesAt(self::SOURCE, $index + 1, $start + (int) mb_strpos($code, $name) + 1);
            }
        }

        self::fail("No line of the source contains {$code}.");
    }
}
