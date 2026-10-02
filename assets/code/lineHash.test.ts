import { describe, expect, it } from 'vitest';
import { formatLineHash, parseLineHash } from './lineHash';

describe('LineHashTest', () => {
    it('Parses #L10 and #L10-L20', () => {
        expect(parseLineHash('#L10')).toEqual({ start: 10, end: 10 });
        expect(parseLineHash('#L10-L20')).toEqual({ start: 10, end: 20 });
        expect(parseLineHash('#L20-L10')).toEqual({ start: 10, end: 20 });
    });

    it('Rejects other hashes', () => {
        for (const hash of ['', '#', '#L', '#L0', '#L010', '#l10', '#L10-20', '#L10-L', '#L10-L0', '#L10L20', '#L1.5', 'L10', '#L10 ']) {
            expect(parseLineHash(hash), hash).toBeNull();
        }
    });

    it('Formats a range as #L10-L20', () => {
        expect(formatLineHash({ start: 10, end: 20 })).toBe('#L10-L20');
        expect(formatLineHash({ start: 10, end: 10 })).toBe('#L10');
    });
});
