import type { Actor } from '../api/types/actor';
import type { DateTime, EventSeq, Proposal, ProposalState, PullRequestState, PullRequestUrl, RequestStatus, SuggestionState, ThreadEntryType, Ulid, UnifiedDiff } from '../api/types/common';
import type { Request as ReviewRequest } from '../api/types/request';
import type { ThreadEntry } from '../api/types/thread-entry';
import type { CarriedFrom } from '../api/types/request-summary';
import type { LineRange } from '../code/lineHash';
import { type SuggestionButton, suggestionButtons } from '../requests/allowedActions';

/** A `request.proposed` entry and what became of it. */
export type ProposalView = {
    eventId: Ulid;
    seq: EventSeq;
    diff: UnifiedDiff | null;
    /** First match: the state of the current proposal, the decision before the next proposal, `superseded`, `withdrawn`. */
    state: ProposalState | 'superseded' | 'withdrawn';
};

/** A thread entry with a body, a diff, or a pull request, or an entry of {@link BODILESS_TYPES}. */
export type CommentView = {
    key: string;
    type: ThreadEntryType;
    author: Actor;
    occurredAt: DateTime;
    /** Null for an entry of {@link BODILESS_TYPES} or a pull request link without a note. */
    body: string | null;
    pullRequestUrl: PullRequestUrl | null;
    /** The state of a pull request that is still linked, else `unknown`. */
    pullRequestState: PullRequestState;
    question: boolean;
    /** Shown before the server confirms it. */
    pending: boolean;
    proposal: ProposalView | null;
};

export type ThreadView = {
    key: string;
    /** Null until Q2 answers. */
    requestId: Ulid | null;
    summary: string;
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
    /** Null for a request of the user. */
    suggestion: SuggestionState | null;
    /** The decisions on the pending suggestion that the caller may take. */
    suggestionButtons: SuggestionButton[];
    carriedFrom: CarriedFrom | null;
};

/** Entries that show without a body, a diff, or a pull request: their caption says what happened. */
const BODILESS_TYPES: readonly ThreadEntryType[] = ['request.proposal_approved', 'request.plan_required', 'request.plan_waived', 'request.suggestion_accepted', 'request.suggestion_dismissed'];

/**
 * Entries without a body, a diff, or a pull request, such as a claim, are left out, except {@link BODILESS_TYPES}.
 */
export function threadView(request: ReviewRequest): ThreadView {
    const range = request.anchor?.range ?? null;
    const comments: CommentView[] = [];

    for (const [index, entry] of request.thread.entries()) {
        if (null !== entry.body || null !== entry.diff || null !== entry.pullRequestUrl || BODILESS_TYPES.includes(entry.type)) {
            comments.push({
                key: entry.eventId,
                type: entry.type,
                author: entry.actor,
                occurredAt: entry.occurredAt,
                body: entry.body,
                pullRequestUrl: entry.pullRequestUrl,
                pullRequestState: request.pullRequests.find(({ url }) => url === entry.pullRequestUrl)?.state ?? 'unknown',
                question: entry.question,
                pending: false,
                proposal: 'request.proposed' === entry.type ? proposalView(entry, request.thread.slice(index + 1), request.proposal) : null,
            });
        }
    }

    return {
        key: request.id,
        requestId: request.id,
        summary: request.summary,
        status: request.status,
        outdated: request.outdated,
        range: null === range ? null : { start: range.startLine, end: range.endLine },
        symbol: request.anchor?.symbol?.fqn ?? null,
        comments,
        replyable: request.allowedActions.includes('reply'),
        decidable: request.allowedActions.includes('approve_proposal'),
        proposalPending: 'pending' === request.proposal?.state,
        suggestion: request.suggestion,
        suggestionButtons: suggestionButtons(request.allowedActions),
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

    return { key: `pending-${pendingCount}`, type, author, occurredAt: new Date().toISOString(), body, pullRequestUrl: null, pullRequestState: 'unknown', question: false, pending: true, proposal: null };
}

export function pendingThread(range: LineRange | null, symbol: string | null, title: string | null, body: string, author: Actor): ThreadView {
    const opened = pendingComment('request.opened', body, author);

    return {
        key: opened.key,
        requestId: null,
        // The `summary` rule of Q3.
        summary: title ?? (body.split('\n').find((line) => '' !== line.trim()) ?? '').trim(),
        status: 'open',
        outdated: null,
        range,
        symbol,
        comments: [opened],
        replyable: false,
        decidable: false,
        proposalPending: false,
        suggestion: null,
        suggestionButtons: [],
        carriedFrom: null,
    };
}

export function linesText(range: LineRange | null): string {
    if (null === range) {
        return 'File';
    }

    return range.start === range.end ? `Line ${range.start}` : `Lines ${range.start} to ${range.end}`;
}
