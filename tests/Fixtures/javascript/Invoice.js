import { round } from './round.js';

/**
 * Sums invoice lines.
 */
export class InvoiceTotals {
    /** Basis points. */
    taxRate = 1000;

    #lines = [];

    static fromLines(lines) {
        const totals = new InvoiceTotals();
        totals.#lines = lines;

        return totals;
    }

    /**
     * The total with tax.
     */
    total() {
        function sum(lines) {
            return lines.reduce((carry, line) => carry + line, 0);
        }

        return round(sum(this.#lines) * (1 + this.taxRate / 10000));
    }
}

export const CURRENCY = 'AUD';

export const formatTotal = (total) => `${CURRENCY} ${total}`;

export function* lineNumbers(lines) {
    yield* lines.keys();
}

let counter = 0;
