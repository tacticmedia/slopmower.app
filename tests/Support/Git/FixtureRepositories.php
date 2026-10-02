<?php

declare(strict_types=1);

namespace App\Tests\Support\Git;

/**
 * File sets for {@see GitRepositoryBuilder::commit()}.
 */
final class FixtureRepositories
{
    public const string SMALL_FILE = 'src/Billing/InvoiceTotals.php';

    public const string NESTED_FILE = 'src/Billing/Tax/TaxRate.php';

    /** `round()` has the lines 11 to 17 with its doc comment, its declaration starts on line 14. */
    public const string SYMBOL_FILE = 'src/Billing/TaxRounding.php';

    public const string LARGE_FILE = 'src/Legacy/UnitOfWork.php';

    public const int LARGE_FILE_LINES = 3500;

    public const string BINARY_FILE = 'public/logo.png';

    /**
     * Nested directories, a PHP file of {@see LARGE_FILE_LINES} lines, and a binary file.
     *
     * @return array<string, string> Content by path
     */
    public static function codeView(): array
    {
        return [
            'README.md' => "# Code view\n",
            self::SMALL_FILE => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Billing;

                final class InvoiceTotals
                {
                    public function total(int $net, int $taxBasisPoints): int
                    {
                        return $net + intdiv($net * $taxBasisPoints, 10000);
                    }
                }

                PHP,
            self::NESTED_FILE => "<?php\n\nnamespace App\\Billing\\Tax;\n\nfinal class TaxRate\n{\n}\n",
            self::SYMBOL_FILE => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Billing;

                final class TaxRounding
                {
                    public const int SCALE = 10000;

                    /**
                     * Rounds half up to whole cents.
                     */
                    public function round(int $amount, int $basisPoints): int
                    {
                        return intdiv($amount * $basisPoints + intdiv(self::SCALE, 2), self::SCALE);
                    }

                    public function ceil(int $amount, int $basisPoints): int
                    {
                        return intdiv($amount * $basisPoints + self::SCALE - 1, self::SCALE);
                    }
                }

                PHP,
            self::LARGE_FILE => self::largePhpFile(),
            self::BINARY_FILE => "\x89PNG\r\n\x1a\n\0\0\0\rIHDR",
        ];
    }

    /**
     * Line N of the lines 4 to 3499 declares `LINE_N`.
     */
    private static function largePhpFile(): string
    {
        $lines = ['<?php', 'final class UnitOfWork', '{'];

        for ($line = 4; $line < self::LARGE_FILE_LINES; ++$line) {
            $lines[] = "    public const LINE_{$line} = {$line};";
        }
        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }
}
