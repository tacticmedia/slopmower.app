import { describe, expect, it } from 'vitest';
import { pathSegments } from './pathSegments';

describe('PathSegmentsTest', () => {
    it('Gives each directory and the last name with its path', () => {
        expect(pathSegments('src/Billing/InvoiceTotals.php')).toEqual([
            { name: 'src', path: 'src' },
            { name: 'Billing', path: 'src/Billing' },
            { name: 'InvoiceTotals.php', path: 'src/Billing/InvoiceTotals.php' },
        ]);
    });

    it('Gives no segment for the root', () => {
        expect(pathSegments('')).toEqual([]);
    });
});
