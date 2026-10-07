import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import type { AgentActor, UserActor } from '../../../assets/api/types/actor';
import type { Anchor, ContentSnapshot, LineRange } from '../../../assets/api/types/anchor';
import type { ApiToken, ApiTokenWithSecret } from '../../../assets/api/types/api-token';
import type { CarryOverResult } from '../../../assets/api/types/carry-over-result';
import type { CommitLink } from '../../../assets/api/types/commit-link';
import type { Action, ActorKind, Proposal, RequestStatus, ThreadEntryType } from '../../../assets/api/types/common';
import type { CommitDiff, CommitDiffFile } from '../../../assets/api/types/commit-diff';
import type { Declaration } from '../../../assets/api/types/declaration';
import type { ErrorBody, ErrorDetails } from '../../../assets/api/types/error';
import type { Event } from '../../../assets/api/types/event';
import type { CandidateList, DeclarationList, MercureAuthorization, ReviewPage, SearchHitList } from '../../../assets/api/types/lists';
import type { Me } from '../../../assets/api/types/me';
import type { Repository } from '../../../assets/api/types/repository';
import type { RepositoryCandidate } from '../../../assets/api/types/repository-candidate';
import type { Request } from '../../../assets/api/types/request';
import type { RequestCommandResult } from '../../../assets/api/types/request-command-result';
import type { RequestSummary } from '../../../assets/api/types/request-summary';
import type { Review } from '../../../assets/api/types/review';
import type { SearchHit } from '../../../assets/api/types/search-hit';
import type { ThreadEntry } from '../../../assets/api/types/thread-entry';

const FIXTURE_DIRECTORY = new URL('../../../docs/specs/fixtures/', import.meta.url);

const SNAPSHOT_CONTEXT_LINES = 3;

/** A fresh copy of a docs/specs fixture. */
export function fixture<T>(name: string): T {
    return JSON.parse(readFileSync(new URL(name, FIXTURE_DIRECTORY), 'utf8')) as T;
}

let lastId = 0;

/** A ULID that no fixture uses. */
export function newId(): string {
    lastId += 1;

    return `01K6F${String(lastId).padStart(21, '0')}`;
}

export function sha1(text: string | Uint8Array): string {
    return createHash('sha1').update(text).digest('hex');
}

/** RFC 3339 with second precision, as the server writes it. */
export function now(offsetSeconds = 0): string {
    return new Date(Date.now() + offsetSeconds * 1000).toISOString().replace(/\.\d{3}Z$/, 'Z');
}

/** The signed-in user of `me.local-user.json`: jan. */
export const USER = fixture<Me>('me.local-user.json').caller as UserActor;

export const AGENT: AgentActor = { kind: 'agent', userId: USER.userId, agentName: 'claude-code laptop', name: 'claude-code laptop', avatarUrl: null };

export function me(features: Partial<Me['features']> = {}): Me {
    const caller = fixture<Me>('me.local-user.json');

    return { ...caller, features: { ...caller.features, ...features } };
}

/** The topics name the tenant of `me.local-user.json`, so that the review selector stays valid. */
export function mercureAuthorization(): MercureAuthorization {
    const authorization = fixture<MercureAuthorization>('lists.mercure-authorization.json');
    const tenantId = me().tenantId;

    return { ...authorization, topics: authorization.topics.map((topic) => topic.replace(/tenants\/[^/]+/, `tenants/${tenantId}`)), expiresAt: now(3600) };
}

export function errorBody(code: string, message: string, details: ErrorDetails | null = null): ErrorBody {
    return { error: { code, message, details } };
}

function repository(overrides: Partial<Repository> = {}): Repository {
    return { ...fixture<Repository>('repository.local-idle.json'), id: newId(), ...overrides } as Repository;
}

export function localRepository(directory: string): Repository {
    return repository({ name: directory, local: { directory }, openReviewCount: 0, createdAt: now() } as Partial<Repository>);
}

export function localCandidate(directory: string, connectedRepositoryId: string | null = null): RepositoryCandidate {
    return { source: 'local', name: directory, connectedRepositoryId, github: null, local: { directory, bare: false, headBranch: 'main' } };
}

export function candidateList(items: RepositoryCandidate[], overrides: Partial<CandidateList> = {}): CandidateList {
    return { items, truncated: false, localEnabled: true, githubListedAt: null, githubConfigureUrl: null, ...overrides };
}

/** V14 with the targets that the test resolves the name to. */
export function declarationList(reviewId: string, items: Declaration[]): DeclarationList {
    return { ...fixture<DeclarationList>('lists.declaration-list.json'), reviewId, items };
}

/** V15 with the items of the fixture, as requests of the source review. */
export function carryOverResult(reviewId: string, fromReviewId: string): CarryOverResult {
    const { items } = fixture<CarryOverResult>('carry-over-result.mixed.json');

    return { reviewId, fromReviewId, items: items.map((item) => ({ ...item, source: { ...item.source, reviewId: fromReviewId } })) };
}

/** V16 with the hits that the test searches for. */
export function searchHitList(reviewId: string, items: SearchHit[], partial = false): SearchHitList {
    return { ...fixture<SearchHitList>('lists.search-hit-list.json'), reviewId, partial, items };
}

/** An open review with a ready symbol index and no requests. */
export function review(overrides: Partial<Review> = {}): Review {
    const base = fixture<Review>('review.open.json');
    const index = fixture<ReviewPage>('lists.review-page.json').items[0]?.index ?? base.index;

    return { ...base, index, ...overrides } as Review;
}

export function token(overrides: Partial<ApiToken> = {}): ApiToken {
    const { secret: _secret, ...base } = fixture<ApiTokenWithSecret>('api-token.with-secret.json');

    return { ...base, id: newId(), createdAt: now(), ...overrides } as ApiToken;
}

/** A secret in the T2 format: `hr_` and 43 base64url characters. */
export function tokenSecret(): string {
    return `hr_${createHash('sha256').update(newId()).digest('base64url')}`;
}

export function snapshot(lines: readonly string[], range: LineRange): ContentSnapshot {
    const region = lines.slice(range.startLine - 1, range.endLine);

    return {
        lines: region,
        truncated: false,
        lineCount: region.length,
        before: lines.slice(Math.max(0, range.startLine - 1 - SNAPSHOT_CONTEXT_LINES), range.startLine - 1),
        after: lines.slice(range.endLine, range.endLine + SNAPSHOT_CONTEXT_LINES),
        normalizedHash: `sha256:${createHash('sha256').update(region.join('\n')).digest('hex')}`,
    };
}

export function threadEntry(seq: number, type: ThreadEntryType, actor: ThreadEntry['actor'], body: string | null, statusAfter: RequestStatus): ThreadEntry {
    return {
        seq,
        eventId: newId(),
        type,
        actor,
        occurredAt: now(),
        body,
        diff: null,
        commentId: 'request.commented' === type ? newId() : null,
        question: false,
        batchId: null,
        commitSha: null,
        pullRequestUrl: null,
        statusAfter,
    };
}

/** `request.opened-range.json` of another anchor and body, or `request.general.json` without an anchor, opened by the user. */
export function request(review: Review, anchor: Anchor | null, body: string, seq: number, requireApproval = false): Request {
    const base = fixture<Request>(null === anchor ? 'request.general.json' : 'request.opened-range.json');
    const createdAt = now();

    return {
        ...base,
        id: newId(),
        reviewId: review.id,
        repositoryId: review.repositoryId,
        anchor,
        summary: summaryText(body),
        body,
        author: USER,
        createdAt,
        updatedAt: createdAt,
        lastEventSeq: seq,
        requireApproval,
        reviewState: review.state,
        reviewCommitSha: review.commitSha,
        location: null === anchor ? null : { target: 'default_branch', targetSha: review.commitSha, status: 'exact', path: anchor.path, range: anchor.range, confidence: 1, reason: null },
        thread: [threadEntry(seq, 'request.opened', USER, body, 'open')],
        allowedActions: userActions('open'),
    };
}

/** The first non-blank line, at most 200 characters. */
function summaryText(body: string): string {
    return (body.split('\n').find((line) => '' !== line.trim()) ?? body).trim().slice(0, 200);
}

/** The `allowedActions` of a user caller on an open review: docs/specs/rest-api-requests.md section 1. */
export function userActions(status: RequestStatus, wontDoBy: ActorKind | null = null, proposal: Proposal | null = null): Action[] {
    const actions: Action[] = ['reply'];
    const unfinished = 'open' === status || 'reopened' === status || 'in_progress' === status;
    if ('in_progress' === status) {
        actions.push('release');
    }
    if (unfinished) {
        actions.push('mark_wont_do');
    }
    if ('ready_for_review' === status || ('wont_do' === status && 'agent' === wontDoBy)) {
        actions.push('verify');
    }
    if ('ready_for_review' === status || 'wont_do' === status || 'verified' === status) {
        actions.push('reopen');
    }
    if ('in_progress' === status && 'pending' === proposal?.state) {
        actions.push('approve_proposal', 'reject_proposal');
    }
    if (unfinished) {
        actions.push('set_plan_requirement');
    }

    return actions;
}

export function requestSummary(request: Request): RequestSummary {
    const { anchor } = request;

    return {
        id: request.id,
        reviewId: request.reviewId,
        repositoryId: request.repositoryId,
        status: request.status,
        awaiting: request.awaiting,
        resolution: request.resolution,
        closed: request.closed,
        anchor: null === anchor ? null : ({ kind: anchor.kind, path: anchor.path, range: anchor.range, symbol: anchor.symbol } as RequestSummary['anchor']),
        summary: request.summary,
        author: request.author,
        claimedBy: request.claimedBy,
        claimedAt: request.claimedAt,
        commentCount: request.commentCount,
        commitCount: request.commitCount,
        pendingCommitCount: request.pendingCommitCount,
        outdated: request.outdated,
        createdAt: request.createdAt,
        updatedAt: request.updatedAt,
        lastEventSeq: request.lastEventSeq,
        carriedFrom: request.carriedFrom,
        requireApproval: request.requireApproval,
        proposal: request.proposal,
    } as RequestSummary;
}

/** The event of the last thread entry. */
export function commandResult(request: Request): RequestCommandResult {
    const entry = request.thread.at(-1);
    const events: Event[] =
        undefined === entry ? [] : [{ ...entry, reviewId: request.reviewId, requestId: request.id, requestIds: [request.id] }];

    return { request: requestSummary(request), allowedActions: request.allowedActions, events };
}

export function commitLink(commitSha: string, subject: string): CommitLink {
    const linkedAt = now();

    return { commitSha, state: 'available', linkedBy: AGENT, linkedAt, note: null, foundAt: linkedAt, subject, committedAt: linkedAt, otherRequestIds: [] };
}

/** `files` in git order. Q9 lists the files that touch the anchor first (D51). */
export function commitDiff(request: Request, commit: CommitLink, files: CommitDiffFile[]): CommitDiff {
    const q9Files = files.toSorted((first, second) => Number(second.touchesAnchor) - Number(first.touchesAnchor));

    return { ...fixture<CommitDiff>('commit-diff.modified.json'), requestId: request.id, commitSha: commit.commitSha, subject: commit.subject ?? '', files: q9Files };
}

export function modifiedFile(path: string, oldText: string, newText: string, touchesAnchor: boolean): CommitDiffFile {
    return {
        status: 'modified',
        path,
        oldPath: null,
        oldBlobSha: sha1(oldText),
        newBlobSha: sha1(newText),
        oldMode: '100644',
        newMode: '100644',
        additions: 1,
        deletions: 1,
        binary: false,
        tooLarge: false,
        touchesAnchor,
    };
}

export function addedFile(path: string, text: string): CommitDiffFile {
    return { ...modifiedFile(path, '', text, false), status: 'added', oldBlobSha: null, oldMode: null, deletions: 0 };
}
