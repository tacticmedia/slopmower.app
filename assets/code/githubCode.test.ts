import { describe, expect, it } from 'vitest';
import { isBinary, lineCount } from './githubCode';

describe('GitHubCodeTest', () => {
    it('Counts lines as V7 does: a final line without LF counts, and an empty file has none', () => {
        expect(['', 'a', 'a\n', 'a\nb', 'a\nb\n'].map(lineCount)).toEqual([0, 1, 1, 2, 2]);
    });

    it('Tells a binary blob by a NUL byte in its first 8,000 bytes, as git does', () => {
        const late = new Uint8Array(8001).fill(65);
        late[8000] = 0;

        expect([isBinary(new Uint8Array([65, 0, 66])), isBinary(late)]).toEqual([true, false]);
    });
});
