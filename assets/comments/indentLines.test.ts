import { describe, expect, it } from 'vitest';
import { indentLines } from './indentLines';

describe('IndentLinesTest', () => {
    it('Indents and dedents every line of a multi-line selection', () => {
        expect(indentLines('one\ntwo\nthree', 1, 5, false)).toEqual({ start: 0, end: 7, text: '  one\n  two' });
        expect(indentLines('  one\n  two\nthree', 3, 9, true)).toEqual({ start: 0, end: 11, text: 'one\ntwo' });
    });

    it('Dedent removes a tab or 1 or 2 leading spaces', () => {
        expect(indentLines('\ta\n b\n   c', 0, 10, true).text).toBe('a\nb\n c');
    });

    it('Dedent leaves a line without indentation unchanged', () => {
        expect(indentLines('a\n  b', 0, 5, true).text).toBe('a\nb');
    });

    it('A selection that ends at the start of a line leaves that line alone', () => {
        expect(indentLines('one\ntwo\nthree', 0, 8, false)).toEqual({ start: 0, end: 7, text: '  one\n  two' });
    });
});
