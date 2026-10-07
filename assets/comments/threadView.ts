import type { Actor } from '../api/types/actor';
import type { DateTime, RequestStatus, ThreadEntryType, Ulid } from '../api/types/common';
import type { Request as ReviewRequest } from '../api/types/request';
import type { LineRange } from '../code/lineHash';

/** A thread entry with a body. */
export type CommentView = {
    key: string;
    type: ThreadEntryType;
    author: Actor;
    occurredAt: DateTime;
    body: string;
    question: boolean;
    /** Shown before the server confirms it. */
    pending: boolean;
};

export type ThreadView = {
    key: string;
    /** Null until Q2 answers. */
    requestId: Ulid | null;
    status: RequestStatus;
    outdated: boolean | null;
    /** Null for a file anchor. */
    range: LineRange | null;
    /** The FQN of a symbol anchor. */
    symbol: string | null;
    comments: CommentView[];
    replyable: boolean;
};

/**
 * Entries without a body, such as a claim, are left out.
 */
export function threadView(request: ReviewRequest): ThreadView {
    const range = request.anchor?.range ?? null;
    const comments: CommentView[] = [];

    for (const entry of request.thread) {
        if (null !== entry.body) {
            comments.push({ key: entry.eventId, type: entry.type, author: entry.actor, occurredAt: entry.occurredAt, body: entry.body, question: entry.question, pending: false });
        }
    }

    return {
        key: request.id,
        requestId: request.id,
        status: request.status,
        outdated: request.outdated,
        range: null === range ? null : { start: range.startLine, end: range.endLine },
        symbol: request.anchor?.symbol?.fqn ?? null,
        comments,
        replyable: request.allowedActions.includes('reply'),
    };
}

let pendingCount = 0;

export function pendingComment(type: ThreadEntryType, body: string, author: Actor): CommentView {
    pendingCount += 1;

    return { key: `pending-${pendingCount}`, type, author, occurredAt: new Date().toISOString(), body, question: false, pending: true };
}

export function pendingThread(range: LineRange | null, symbol: string | null, body: string, author: Actor): ThreadView {
    const opened = pendingComment('request.opened', body, author);

    return { key: opened.key, requestId: null, status: 'open', outdated: null, range, symbol, comments: [opened], replyable: false };
}

export function linesText(range: LineRange | null): string {
    if (null === range) {
        return 'File';
    }

    return range.start === range.end ? `Line ${range.start}` : `Lines ${range.start} to ${range.end}`;
}
