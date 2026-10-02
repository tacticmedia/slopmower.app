import type { AnchorSummary } from '../api/types/anchor';
import { formatLineHash } from '../code/lineHash';
import { fileHref } from '../review/fileHref';

/**
 * `path:L5-L9`, `path:L5`, or the path of a file anchor.
 */
export function anchorLocation({ path, range }: Pick<AnchorSummary, 'path' | 'range'>): string {
    return null === range ? path : `${path}:${lineHash(range).slice(1)}`;
}

/**
 * The file view of the review, with the anchor lines selected.
 */
export function anchorHref(reviewId: string, { path, range }: AnchorSummary): string {
    return fileHref(reviewId, path) + (null === range ? '' : lineHash(range));
}

function lineHash(range: { startLine: number; endLine: number }): string {
    return formatLineHash({ start: range.startLine, end: range.endLine });
}
