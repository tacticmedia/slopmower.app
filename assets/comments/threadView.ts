import type { Actor } from '../api/types/actor';
import type { DateTime, EventSeq, Proposal, ProposalState, PullRequestUrl, RequestStatus, ThreadEntryType, Ulid, UnifiedDiff } from '../api/types/common';
import type { Request as ReviewRequest } from '../api/types/request';
import type { ThreadEntry } from '../api/types/thread-entry';
import type { CarriedFrom } from '../api/types/request-summary';
import type { LineRange } from '../code/lineHash';

/** A `request.proposed` entry and what became of it. */
export type ProposalView = {
    eventId: Ulid;
    seq: EventSeq;
    diff: UnifiedDiff | null;
    /** First match: the state of the current proposal, the decision before the next proposal, `superseded`, `withdrawn`. */
    state: ProposalState | 'superseded' | 'withdrawn';
};

/** A thread entry with a body, a diff, a pull request, or an approval. */
export type CommentView = {
    key: string;
    type: ThreadEntryType;
    author: Actor;
    occurredAt: DateTime;
    /** Null only for an approval or a pull request link without a note. */
    body: string | null;
    pullRequestUrl: PullRequestUrl | null;
    question: boolean;
    /** Shown before the server confirms it. */
    pending: boolean;
    proposal: ProposalView | null;
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
    /** The caller may approve or reject the pending proposal. */
    decidable: boolean;
    proposalPending: boolean;
    carriedFrom: CarriedFrom | null;
};

/**
 * Entries without a body, a diff, or a pull request, such as a claim, are left out. An approval without a note stays.
 */
export function threadView(request: ReviewRequest): ThreadView {
    const range = request.anchor?.range ?? null;
    const comments: CommentView[] = [];

    for (const [index, entry] of request.thread.entries()) {
        if (null !== entry.body || null !== entry.diff || null !== entry.pullRequestUrl || 'request.proposal_approved' === entry.type) {
            comments.push({
                key: entry.eventId,
                type: entry.type,
                author: entry.actor,
                occurredAt: entry.occurredAt,
                body: entry.body,
                pullRequestUrl: entry.pullRequestUrl,
                question: entry.question,
                pending: false,
                proposal: 'request.proposed' === entry.type ? proposalView(entry, request.thread.slice(index + 1), request.proposal) : null,
            });
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
        decidable: request.allowedActions.includes('approve_proposal'),
        proposalPending: 'pending' === request.proposal?.state,
        carriedFrom: request.carriedFrom,
    };
}

function proposalView(entry: ThreadEntry, laterEntries: readonly ThreadEntry[], current: Proposal | null): ProposalView {
    const state = current?.eventId === entry.eventId ? current.state : laterState(laterEntries);

    return { eventId: entry.eventId, seq: entry.seq, diff: entry.diff, state };
}

/**
 * A decision always names the pending proposal, which is the latest earlier one, so the thread order is enough.
 */
function laterState(laterEntries: readonly ThreadEntry[]): ProposalView['state'] {
    for (const { type } of laterEntries) {
        switch (type) {
            case 'request.proposal_approved':
                return 'approved';
            case 'request.proposal_rejected':
                return 'rejected';
            case 'request.proposed':
                return 'superseded';
        }
    }

    return 'withdrawn';
}

let pendingCount = 0;

export function pendingComment(type: ThreadEntryType, body: string, author: Actor): CommentView {
    pendingCount += 1;

    return { key: `pending-${pendingCount}`, type, author, occurredAt: new Date().toISOString(), body, pullRequestUrl: null, question: false, pending: true, proposal: null };
}

export function pendingThread(range: LineRange | null, symbol: string | null, body: string, author: Actor): ThreadView {
    const opened = pendingComment('request.opened', body, author);

    return { key: opened.key, requestId: null, status: 'open', outdated: null, range, symbol, comments: [opened], replyable: false, decidable: false, proposalPending: false, carriedFrom: null };
}

export function linesText(range: LineRange | null): string {
    if (null === range) {
        return 'File';
    }

    return range.start === range.end ? `Line ${range.start}` : `Lines ${range.start} to ${range.end}`;
}
