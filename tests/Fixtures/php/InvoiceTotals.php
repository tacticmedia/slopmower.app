<?php

declare(strict_types=1);

namespace App\Billing;

use App\Attribute\Audited;

/**
 * Totals of one invoice.
 */
#[Audited]
final readonly class InvoiceTotals
{
    public const int CENTS = 100, BASIS_POINTS = 10_000;

    public function __construct(
        /** Net amounts in cents. */
        private array $lines,
        public int $taxBasisPoints = 1_000,
    ) {
    }

    /**
     * Sum of all lines.
     */
    #[Audited]
    public function total(): int
    {
        return $this->lines |> array_sum(...);
    }

    public function withTax(int $taxBasisPoints): static
    {
        return clone($this, ['taxBasisPoints' => $taxBasisPoints]);
    }
}
