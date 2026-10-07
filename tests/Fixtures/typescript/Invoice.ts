import { logged, sealed } from './decorators.ts';

export interface Line {
    /** Cents. */
    amount: number;
    label(): string;
}

export type Currency = 'AUD' | 'NZD';

export enum Status {
    Draft,
    Paid = 'paid',
}

/**
 * Sums invoice lines.
 */
@sealed
export class InvoiceTotals<const T extends readonly Line[]> {
    @logged accessor lines: T;

    constructor(lines: T) {
        this.lines = lines;
    }

    total(): number;
    total(currency: Currency): string;
    /** The sum in cents. */
    total(currency?: Currency): number | string {
        using scope = openScope();
        const sum = this.lines.reduce((carry, line) => carry + line.amount, 0);

        return undefined === currency ? sum : `${currency} ${sum}`;
    }

    /** Logs each call. */
    @logged
    clear(): void {}
}

export namespace Defaults {
    export const CURRENCY = 'AUD' satisfies Currency;

    export function rate(): number {
        return 1000;
    }
}

declare function openScope(): Disposable;
