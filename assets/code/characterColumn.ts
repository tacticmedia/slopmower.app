/**
 * The V14 `column` of a position: 1-based, in Unicode characters of the line.
 *
 * @param offset UTF-16 code units into line `line` of `text`, as the token events of the code view count them
 */
export function characterColumn(text: string, line: number, offset: number): number {
    const lineText = text.split('\n')[line - 1] ?? '';

    return Array.from(lineText.slice(0, offset)).length + 1;
}
