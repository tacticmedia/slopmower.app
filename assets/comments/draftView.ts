import type { Ulid } from '../api/types/common';
import type { Draft } from '../api/types/draft';
import type { LineRange } from '../code/lineHash';
import { linesText } from './threadView';

/** A pending draft of the caller: a new request or a reply. */
export type DraftView = {
    key: string;
    /** Null until D2 answers. */
    draftId: Ulid | null;
    /** Null for a file anchor, a general request, and a reply. */
    range: LineRange | null;
    /** The header, for example "Line 3", "src/A.php:L3", "General", or the request of a reply. */
    label: string;
    /** On the Requests tab: the drafted lines or the request of a reply. */
    href: string | null;
    body: string;
    /** The "Ask for a plan first" choice of a new request. Null for a reply. */
    requireApproval: boolean | null;
};

/**
 * The new request drafts of the file. Replies have no place in the code view.
 */
export function fileDraftViews(drafts: readonly Draft[], path: string): DraftView[] {
    const views: DraftView[] = [];

    for (const draft of drafts) {
        if ('new_request' === draft.kind && draft.anchor?.path === path) {
            const { range: anchorRange } = draft.anchor;
            const range = null === anchorRange ? null : { start: anchorRange.startLine, end: anchorRange.endLine };
            views.push({ key: draft.id, draftId: draft.id, range, label: linesText(range), href: null, body: draft.body, requireApproval: draft.requireApproval });
        }
    }

    return views;
}

/** The review button of a comment box. */
export function reviewLabel(drafts: readonly Draft[]): string {
    return 0 === drafts.length ? 'Start a review' : 'Add review comment';
}

let pendingCount = 0;

export function pendingDraft(range: LineRange | null, body: string, requireApproval: boolean): DraftView {
    pendingCount += 1;

    return { key: `pending-draft-${pendingCount}`, draftId: null, range, label: linesText(range), href: null, body, requireApproval };
}
