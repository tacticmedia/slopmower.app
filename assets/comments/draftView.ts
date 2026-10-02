import type { Ulid } from '../api/types/common';
import type { Draft } from '../api/types/draft';
import type { LineRange } from '../code/lineHash';

/** A pending new request of the caller. */
export type DraftView = {
    key: string;
    /** Null until D2 answers. */
    draftId: Ulid | null;
    /** Null for a file anchor. */
    range: LineRange | null;
    body: string;
};

/**
 * The new request drafts of the file. Replies have no place in the code view.
 */
export function fileDraftViews(drafts: readonly Draft[], path: string): DraftView[] {
    const views: DraftView[] = [];

    for (const draft of drafts) {
        if ('new_request' === draft.kind && draft.anchor.path === path) {
            const { range } = draft.anchor;
            views.push({ key: draft.id, draftId: draft.id, range: null === range ? null : { start: range.startLine, end: range.endLine }, body: draft.body });
        }
    }

    return views;
}

let pendingCount = 0;

export function pendingDraft(range: LineRange | null, body: string): DraftView {
    pendingCount += 1;

    return { key: `pending-draft-${pendingCount}`, draftId: null, range, body };
}
