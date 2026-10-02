import { describe, expect, it } from 'vitest';
import { parseLineRange } from './lineRangeInput';

describe('LineRangeInputTest', () => {
    it('Rejects a start after the end and lines outside the file', () => {
        expect(parseLineRange('3', '4', 13)).toEqual({ range: { start: 3, end: 4 }, errors: null });
        expect(parseLineRange(' 13 ', '13', 13)).toEqual({ range: { start: 13, end: 13 }, errors: null });

        expect(parseLineRange('5', '3', 13)).toEqual({ range: null, errors: { end: 'The end line must not be before the start line.' } });
        expect(parseLineRange('3', '14', 13)).toEqual({ range: null, errors: { end: 'The file has 13 lines.' } });
        expect(parseLineRange('0', '3', 13)).toEqual({ range: null, errors: { start: 'Enter a line number.' } });
        expect(parseLineRange('', '2.5', 1)).toEqual({ range: null, errors: { start: 'Enter a line number.', end: 'Enter a line number.' } });
        expect(parseLineRange('2', '2', 1)).toEqual({ range: null, errors: { start: 'The file has 1 line.', end: 'The file has 1 line.' } });
    });
});
