import { describe, expect, it } from 'vitest';
import { fileHref } from './fileHref';

describe('FileHrefTest', () => {
    it('Encodes each path segment and keeps the slashes', () => {
        expect(fileHref('01K6B3A1B2C3D4E5F6G7H8J9KM', 'docs/Read me #1?.md')).toBe('/reviews/01K6B3A1B2C3D4E5F6G7H8J9KM/files/docs/Read%20me%20%231%3F.md');
    });
});
