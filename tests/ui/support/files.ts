import type { Symbol as CodeSymbol } from '../../../assets/api/types/symbol';

export const SMALL_FILE = 'src/Billing/InvoiceTotals.php';

const NESTED_FILE = 'src/Billing/Tax/TaxRate.php';

/** `round()` has the lines 11 to 17 with its doc comment, its declaration starts on line 14. */
export const SYMBOL_FILE = 'src/Billing/TaxRounding.php';

export const LARGE_FILE = 'src/Legacy/UnitOfWork.php';

export const BINARY_FILE = 'public/logo.png';

export const ROUND_FQN = 'App\\Billing\\TaxRounding::round()';

/** Null content stands for a binary file. */
export type FileSet = Record<string, string | null>;

const LARGE_FILE_LINES = 3500;

export const SMALL_FILE_TEXT = `<?php

declare(strict_types=1);

namespace App\\Billing;

final class InvoiceTotals
{
    public function total(int $net, int $taxBasisPoints): int
    {
        return $net + intdiv($net * $taxBasisPoints, 10000);
    }
}
`;

const SYMBOL_FILE_TEXT = `<?php

declare(strict_types=1);

namespace App\\Billing;

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
`;

/** Line N of the lines 4 to 3499 declares `LINE_N`. */
function largePhpFile(): string {
    const lines = ['<?php', 'final class UnitOfWork', '{'];
    for (let line = 4; line < LARGE_FILE_LINES; line++) {
        lines.push(`    public const LINE_${line} = ${line};`);
    }
    lines.push('}');

    return `${lines.join('\n')}\n`;
}

/** Nested directories, a PHP file of 3,500 lines, and a binary file. */
export function codeViewFiles(): FileSet {
    return {
        'README.md': '# Code view\n',
        [SMALL_FILE]: SMALL_FILE_TEXT,
        [NESTED_FILE]: '<?php\n\nnamespace App\\Billing\\Tax;\n\nfinal class TaxRate\n{\n}\n',
        [SYMBOL_FILE]: SYMBOL_FILE_TEXT,
        [LARGE_FILE]: largePhpFile(),
        [BINARY_FILE]: null,
    };
}

function symbol(kind: string, fqn: string, name: string, parentFqn: string | null, startLine: number, endLine: number, declarationStartLine = startLine): CodeSymbol {
    return { kind, fqn, name, parentFqn, range: { startLine, endLine }, declarationStartLine, docStartLine: declarationStartLine === startLine ? null : startLine };
}

/** V9 of the PHP files of {@link codeViewFiles}. */
export function codeViewSymbols(): Record<string, CodeSymbol[]> {
    const rounding = 'App\\Billing\\TaxRounding';
    const totals = 'App\\Billing\\InvoiceTotals';

    return {
        [SMALL_FILE]: [symbol('class', totals, 'InvoiceTotals', null, 7, 13), symbol('method', `${totals}::total()`, 'total', totals, 9, 12)],
        [SYMBOL_FILE]: [
            symbol('class', rounding, 'TaxRounding', null, 7, 23),
            symbol('class_constant', `${rounding}::SCALE`, 'SCALE', rounding, 9, 9),
            symbol('method', ROUND_FQN, 'round', rounding, 11, 17, 14),
            symbol('method', `${rounding}::ceil()`, 'ceil', rounding, 19, 22),
        ],
    };
}
