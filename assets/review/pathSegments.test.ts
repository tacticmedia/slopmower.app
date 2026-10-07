import { describe, expect, it } from 'vitest';
import { formatSize, pathSegments } from './pathSegments';

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

    it('Writes sizes with three significant digits in binary units', () => {
        expect(formatSize(512)).toBe('512 Bytes');
        expect(formatSize(3184)).toBe('3.11 KB');
        expect(formatSize(80998)).toBe('79.1 KB');
        expect(formatSize(2621440)).toBe('2.5 MB');
    });
});
