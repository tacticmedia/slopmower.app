/** `text` replaces `value.slice(start, end)`. */
export type LineEdit = { start: number; end: number; text: string };

/**
 * The lines that the selection touches, indented by 2 spaces, or dedented by a tab or by up to 2 spaces. A selection
 * that ends at the start of a line does not touch that line.
 */
export function indentLines(value: string, selectionStart: number, selectionEnd: number, dedent: boolean): LineEdit {
    const start = value.slice(0, selectionStart).lastIndexOf('\n') + 1;
    const last = selectionEnd > selectionStart && '\n' === value[selectionEnd - 1] ? selectionEnd - 1 : selectionEnd;
    const lineEnd = value.indexOf('\n', last);
    const end = -1 === lineEnd ? value.length : lineEnd;
    const text = value
        .slice(start, end)
        .split('\n')
        .map((line) => (dedent ? line.replace(/^(\t| {1,2})/, '') : `  ${line}`))
        .join('\n');

    return { start, end, text };
}
