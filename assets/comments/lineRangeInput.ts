import type { LineRange } from '../code/lineHash';

export type LineRangeErrors = { start?: string; end?: string };

export type LineRangeResult = { range: LineRange; errors: null } | { range: null; errors: LineRangeErrors };

const LINE_NUMBER = /^[1-9]\d*$/;

/**
 * The "Comment on lines" fields: whole line numbers inside the file, the start not after the end.
 */
export function parseLineRange(startText: string, endText: string, lineCount: number): LineRangeResult {
    const start = lineNumber(startText, lineCount);
    const end = lineNumber(endText, lineCount);
    const errors: LineRangeErrors = {};

    if ('string' === typeof start) {
        errors.start = start;
    }
    if ('string' === typeof end) {
        errors.end = end;
    } else if ('number' === typeof start && end < start) {
        errors.end = 'The end line must not be before the start line.';
    }

    if ('number' === typeof start && 'number' === typeof end && undefined === errors.end) {
        return { range: { start, end }, errors: null };
    }

    return { range: null, errors };
}

/**
 * The line number, or the message that rejects the text.
 */
function lineNumber(text: string, lineCount: number): number | string {
    const trimmed = text.trim();

    if (!LINE_NUMBER.test(trimmed)) {
        return 'Enter a line number.';
    }
    const line = Number(trimmed);

    return line <= lineCount ? line : `The file has ${lineCount} ${1 === lineCount ? 'line' : 'lines'}.`;
}
