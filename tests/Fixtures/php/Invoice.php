<?php

namespace App\Billing;

interface HasNumber
{
    public const string PREFIX = 'INV-';

    public function number(): string;
}

trait Numbered
{
    private int $sequence = 0;
}

enum Status: string
{
    case Draft = 'draft';
    case Paid = 'paid';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

class Invoice implements HasNumber
{
    use Numbered;

    public string $customer {
        set => trim($value);
    }

    public int $a = 1, $b = 2;

    public function number(): string
    {
        $formatter = new class {
            public function format(int $n): string
            {
                return (string) $n;
            }
        };

        return self::PREFIX.$formatter->format($this->sequence);
    }
}

function invoice_total(Invoice $invoice): int
{
    return 0;
}

const DEFAULT_CURRENCY = 'AUD';
