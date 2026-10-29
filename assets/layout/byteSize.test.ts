import { describe, expect, it } from 'vitest';
import { formatSize } from './byteSize';

describe('ByteSizeTest', () => {
    it('Writes sizes with three significant digits in binary units', () => {
        expect(formatSize(512)).toBe('512 Bytes');
        expect(formatSize(3184)).toBe('3.11 KB');
        expect(formatSize(80998)).toBe('79.1 KB');
        expect(formatSize(2621440)).toBe('2.5 MB');
        expect(formatSize(2 * 1024 ** 3)).toBe('2 GB');
    });
});
