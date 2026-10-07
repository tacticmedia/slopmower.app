import type { Actor } from '../../../assets/api/types/actor';
import type { Anchor, AnchorInput } from '../../../assets/api/types/anchor';
import type { Blame } from '../../../assets/api/types/blame';
import type { CommitLink } from '../../../assets/api/types/commit-link';
import type { ActorKind, Awaiting, Proposal, RequestStatus, Resolution, ThreadEntryType } from '../../../assets/api/types/common';
import type { Draft } from '../../../assets/api/types/draft';
import type { FileContent } from '../../../assets/api/types/file-content';
import type {
    ApproveProposalInput,
    CloseReviewInput,
    CommentInput,
    CreateDraftInput,
    OpenRequestInput,
    OptionalNoteInput,
    PlanRequirementInput,
    RejectProposalInput,
    RequiredNoteInput,
    SubmitDraftsInput,
    UpdateDraftInput,
    ViewedFileInput,
    WontDoInput,
} from '../../../assets/api/types/inputs';
import type { Request } from '../../../assets/api/types/request';
import type { Review } from '../../../assets/api/types/review';
import type { Symbol as CodeSymbol } from '../../../assets/api/types/symbol';
import type { TreeEntry } from '../../../assets/api/types/tree-entry';
import { AGENT, commandResult, newId, now, request as openedRequest, requestSummary, review as reviewBuilder, sha1, snapshot, threadEntry, USER, userActions } from './builders';
import { codeViewFiles, codeViewSymbols, type FileSet } from './files';
import { type ApiCall, apiError, json, type MockApi, type MockResponse, noContent, text } from './mockApi';

type StoredFile = { content: FileContent; bytes: string; lines: string[] };

export type FakeReviewOptions = {
    review?: Partial<Review>;
    files?: FileSet;
    symbols?: Record<string, CodeSymbol[]>;
};

/** The fields of `RequestState` that `awaiting`, `closed`, and `allowedActions` derive from. */
type Tracking = {
    statusBeforeClaim: RequestStatus | null;
    wontDoBy: ActorKind | null;
    lastUserCommentSeq: number | null;
    lastAgentEventSeq: number | null;
    lastAgentEventIsQuestion: boolean;
};

type Transition = { status?: RequestStatus; resolution?: Resolution | null; proposal?: Proposal | null; requireApproval?: boolean };

type ThreadEntry = Request['thread'][number];

const LANGUAGES: Record<string, string> = { php: 'php', md: 'markdown', ts: 'typescript' };

/** The V7 languages that V9 parses. */
const SYMBOL_LANGUAGES: ReadonlySet<string | null> = new Set(['php', 'javascript', 'jsx', 'typescript', 'tsx', 'python', 'go', 'rust', 'java', 'csharp', 'ruby', 'shellscript', 'scala', 'zig', 'lua', 'twig']);

/** The `allowedActions` value of each command path of Q4, Q11 to Q13, Q15, Q16, and Q18. */
const COMMAND_ACTIONS: Record<string, string> = {
    comments: 'reply',
    'wont-do': 'mark_wont_do',
    'approve-proposal': 'approve_proposal',
    'reject-proposal': 'reject_proposal',
    'plan-requirement': 'set_plan_requirement',
};

/** Rules 1 to 5 of architecture section 6, as `AwaitingPolicy` applies them. A proposal decision is a user message. */
function awaitingOf(status: RequestStatus, closed: boolean, tracking: Tracking, proposal: Proposal | null): Awaiting {
    if (closed) {
        return 'none';
    }
    if (null !== tracking.lastUserCommentSeq && tracking.lastUserCommentSeq > (tracking.lastAgentEventSeq ?? 0)) {
        return 'agent';
    }
    if (tracking.lastAgentEventIsQuestion || 'pending' === proposal?.state) {
        return 'user';
    }

    return 'ready_for_review' === status || 'wont_do' === status ? 'user' : 'agent';
}

/** One review of the docs/specs REST API in memory: V3, V4, V6 to V9, V11 to V13, Q1 to Q4, Q6, Q11 to Q13, Q15, Q16, Q18, and D1 to D5, for the user of `me.local-user.json`. */
export class FakeReview {
    review: Review;
    requests: Request[] = [];
    drafts: Draft[] = [];
    viewedPaths: string[] = [];
    /** V17 by path. A renderable file without an entry has one commit for all its lines. */
    readonly blames = new Map<string, Pick<Blame, 'ignoreRevsFile' | 'ranges' | 'commits'>>();
    deleted = false;
    readonly #files = new Map<string, StoredFile>();
    readonly #symbols: Record<string, CodeSymbol[]>;
    readonly #draftAnchors = new Map<string, AnchorInput | null>();
    readonly #tracking = new Map<string, Tracking>();
    #seq: number;

    constructor(api: MockApi, options: FakeReviewOptions = {}) {
        this.review = reviewBuilder({ id: newId(), ...options.review });
        this.#seq = this.review.lastEventSeq;
        this.#symbols = options.symbols ?? codeViewSymbols();
        for (const [path, content] of Object.entries(options.files ?? codeViewFiles())) {
            this.#files.set(path, this.#storedFile(path, content));
        }
        this.#register(api);
        this.#registerRequests(api);
    }

    get id(): string {
        return this.review.id;
    }

    fileUrl(path: string, hash = ''): string {
        return `/reviews/${this.id}/files/${path}${hash}`;
    }

    seedLines(path: string, startLine: number, endLine: number, body: string): Request {
        return this.#openRequest({ kind: startLine === endLine ? 'line' : 'range', path, range: { startLine, endLine } }, body);
    }

    seedSymbol(path: string, fqn: string, body: string): Request {
        return this.#openRequest({ kind: 'symbol', path, symbol: { fqn } }, body);
    }

    seedGeneral(body: string, requireApproval = false): Request {
        return this.#openRequest(null, body, null, requireApproval);
    }

    /** A D2 draft, as if the user had written it earlier. */
    seedDraft(input: CreateDraftInput): Draft {
        return this.#createDraft(input);
    }

    close(note: string | null = null): void {
        this.review = { ...this.review, state: 'closed', closedAt: now(), closedBy: USER, closeNote: note };
        this.drafts = [];
    }

    agentClaims(id: string): void {
        this.#apply(id, this.#entry('request.claimed', AGENT, null, 'in_progress'), { status: 'in_progress' });
    }

    agentLinks(id: string, commit: CommitLink): void {
        const current = this.#apply(id, { ...this.#entry('commit.linked', AGENT, commit.note, 'in_progress'), commitSha: commit.commitSha });
        this.requests[this.requests.indexOf(current)] = { ...current, commits: [...current.commits, commit], commitCount: current.commitCount + 1 };
    }

    agentLinksPullRequest(id: string, url: string, note: string | null = null): void {
        const current = this.#apply(id, { ...this.#entry('pull_request.linked', AGENT, note, this.find(id).status), pullRequestUrl: url });
        this.requests[this.requests.indexOf(current)] = { ...current, pullRequestUrls: [...current.pullRequestUrls, url] };
    }

    agentUnlinksPullRequest(id: string, url: string): void {
        const current = this.#apply(id, { ...this.#entry('pull_request.unlinked', AGENT, null, this.find(id).status), pullRequestUrl: url });
        this.requests[this.requests.indexOf(current)] = { ...current, pullRequestUrls: current.pullRequestUrls.filter((linked) => linked !== url) };
    }

    agentMarksReady(id: string, note: string): void {
        this.#apply(id, this.#entry('request.ready_for_review', AGENT, note, 'ready_for_review'), { status: 'ready_for_review', resolution: 'fixed' });
    }

    agentAsks(id: string, body: string): void {
        this.#apply(id, { ...this.#entry('request.commented', AGENT, body, this.find(id).status), question: true });
    }

    /** A new proposal replaces the current one. */
    agentProposes(id: string, plan: string, diff: string | null = null): ThreadEntry {
        const entry = { ...this.#entry('request.proposed', AGENT, plan, 'in_progress'), diff };
        this.#apply(id, entry, { proposal: { eventId: entry.eventId, state: 'pending' } });

        return entry;
    }

    /** A location recompute after a default-branch commit changed the anchored lines. */
    markOutdated(id: string, targetSha: string): void {
        const current = this.find(id);
        if (null === current.anchor) {
            throw new Error(`The request ${id} is general and has no location.`);
        }
        const { path, range } = current.anchor;
        this.requests[this.requests.indexOf(current)] = { ...current, outdated: true, location: { target: 'default_branch', targetSha, status: 'changed', path, range, confidence: 0.8, reason: null } };
    }

    find(id: string): Request {
        const found = this.requests.find((request) => request.id === id);
        if (undefined === found) {
            throw new Error(`The review has no request ${id}.`);
        }

        return found;
    }

    #owns(requestId: string): boolean {
        return this.requests.some((request) => request.id === requestId);
    }

    /** The request as Q3 shows it to the user now. */
    #present(request: Request): Request {
        const allowedActions = 'closed' === this.review.state ? [] : userActions(request.status, this.#trackingOf(request.id).wontDoBy, request.proposal);

        return { ...request, reviewState: this.review.state, allowedActions };
    }

    #openRequest(input: AnchorInput | null, body: string, batchId: string | null = null, requireApproval = false): Request {
        const seq = ++this.#seq;
        const opened = openedRequest(this.review, null === input ? null : this.#anchor(input), body, seq, requireApproval);
        const [entry] = opened.thread;
        const request = undefined === entry ? opened : { ...opened, thread: [{ ...entry, batchId }] };
        this.requests.push(request);
        this.#tracking.set(request.id, { statusBeforeClaim: null, wontDoBy: null, lastUserCommentSeq: null, lastAgentEventSeq: null, lastAgentEventIsQuestion: false });

        return request;
    }

    #trackingOf(id: string): Tracking {
        const tracking = this.#tracking.get(id);
        if (undefined === tracking) {
            throw new Error(`The review has no request ${id}.`);
        }

        return tracking;
    }

    /**
     * Appends the thread entry and applies the transition as `RequestReducer` does. `claimedBy` lives only in
     * `in_progress`; `closed` and `awaiting` are derived. Release, won't do, and reopen set the proposal to null.
     */
    #apply(id: string, entry: ThreadEntry, transition: Transition = {}): Request {
        const current = this.find(id);
        const tracking = this.#trackingOf(id);
        const status = transition.status ?? current.status;
        if ('in_progress' === status && 'in_progress' !== current.status) {
            tracking.statusBeforeClaim = current.status;
        }
        tracking.wontDoBy = 'wont_do' !== status ? null : 'wont_do' === transition.status ? entry.actor.kind : tracking.wontDoBy;
        // A pull request link or unlink is no agent event for the `awaiting` rules.
        if ('agent' === entry.actor.kind && !['pull_request.linked', 'pull_request.unlinked'].includes(entry.type)) {
            tracking.lastAgentEventSeq = entry.seq;
            tracking.lastAgentEventIsQuestion = 'request.commented' === entry.type && entry.question;
        } else if ('user' === entry.actor.kind && ['request.commented', 'request.proposal_approved', 'request.proposal_rejected'].includes(entry.type)) {
            tracking.lastUserCommentSeq = entry.seq;
        }
        const closed = 'verified' === status || ('wont_do' === status && 'user' === tracking.wontDoBy);
        const proposalEnds = ['request.released', 'request.wont_do', 'request.reopened'].includes(entry.type);
        const proposal = proposalEnds ? null : undefined === transition.proposal ? current.proposal : transition.proposal;
        const claim = 'in_progress' === status ? { claimedBy: current.claimedBy ?? entry.actor, claimedAt: current.claimedAt ?? entry.occurredAt } : { claimedBy: null, claimedAt: null };
        const next = {
            ...current,
            ...claim,
            status,
            closed,
            proposal,
            awaiting: awaitingOf(status, closed, tracking, proposal),
            resolution: undefined === transition.resolution ? current.resolution : transition.resolution,
            requireApproval: transition.requireApproval ?? current.requireApproval,
            commentCount: current.commentCount + ('request.commented' === entry.type ? 1 : 0),
            thread: [...current.thread, entry],
            updatedAt: entry.occurredAt,
            lastEventSeq: entry.seq,
        } as Request;
        this.requests[this.requests.indexOf(current)] = next;

        return next;
    }

    /** The user commands of the request detail. */
    command(id: string, command: string, input: unknown): Request {
        const { status } = this.find(id);
        switch (command) {
            case 'comments':
                return this.#apply(id, this.#entry('request.commented', USER, (input as CommentInput).body, status));
            case 'reopen':
                return this.#apply(id, this.#entry('request.reopened', USER, (input as RequiredNoteInput).note, 'reopened'), { status: 'reopened', resolution: null });
            case 'wont-do':
                return this.#apply(id, this.#entry('request.wont_do', USER, (input as WontDoInput).reason, 'wont_do'), { status: 'wont_do', resolution: 'wont_do' });
            case 'release': {
                const before = this.#trackingOf(id).statusBeforeClaim ?? 'open';

                return this.#apply(id, this.#entry('request.released', USER, (input as OptionalNoteInput).note ?? null, before), { status: before });
            }
            case 'verify':
                return this.#apply(id, this.#entry('request.verified', USER, (input as OptionalNoteInput).note ?? null, 'verified'), { status: 'verified' });
            case 'approve-proposal': {
                const { proposalEventId, note } = input as ApproveProposalInput;

                return this.#apply(id, this.#entry('request.proposal_approved', USER, note ?? null, status), { proposal: { eventId: proposalEventId, state: 'approved' } });
            }
            case 'reject-proposal': {
                const { proposalEventId, note } = input as RejectProposalInput;

                return this.#apply(id, this.#entry('request.proposal_rejected', USER, note, status), { proposal: { eventId: proposalEventId, state: 'rejected' } });
            }
            case 'plan-requirement': {
                const { requireApproval } = input as PlanRequirementInput;
                if (requireApproval === this.find(id).requireApproval) {
                    throw new Error('The fake changes the plan requirement only to the other value.');
                }

                return this.#apply(id, this.#entry(requireApproval ? 'request.plan_required' : 'request.plan_waived', USER, null, status), { requireApproval });
            }
            default:
                throw new Error(`The fake knows no command ${command}.`);
        }
    }

    #commandResult(request: Request, status: number): MockResponse {
        return json('request-command-result.schema.json', commandResult(this.#present(request)), status);
    }

    #entry(type: ThreadEntryType, actor: Actor, body: string | null, statusAfter: RequestStatus): ThreadEntry {
        return threadEntry(++this.#seq, type, actor, body, statusAfter);
    }

    #storedFile(path: string, content: string | null): StoredFile {
        const bytes = content ?? '\x89PNG\r\n\x1a\n';
        const lines = null === content ? [] : content.split('\n');
        if (null !== content && content.endsWith('\n')) {
            lines.pop();
        }

        return {
            bytes,
            lines,
            content: {
                reviewId: this.id,
                commitSha: this.review.commitSha,
                path,
                blobSha: sha1(bytes),
                mode: '100644',
                type: 'file',
                sizeBytes: bytes.length,
                language: LANGUAGES[path.slice(path.lastIndexOf('.') + 1)] ?? null,
                binary: null === content,
                tooLarge: false,
                renderable: null !== content,
                lineCount: null === content ? null : lines.length,
                symlinkTarget: null,
                rawUrl: `/api/v1/reviews/${this.id}/raw?path=${encodeURIComponent(path)}`,
            },
        };
    }

    /** V7 to V9 answer 404 for a path that the review commit lacks. */
    #withFile(query: URLSearchParams, respond: (file: StoredFile) => MockResponse): MockResponse {
        const path = query.get('path') ?? '';
        const file = this.#files.get(path);

        return undefined === file ? apiError(404, 'not_found', `No file ${path} exists at the review commit.`) : respond(file);
    }

    #file(path: string | null): StoredFile {
        const file = null === path ? undefined : this.#files.get(path);
        if (undefined === file) {
            throw new Error(`The review has no file ${String(path)}.`);
        }

        return file;
    }

    #anchor(input: AnchorInput): Anchor {
        const file = this.#file(input.path);
        const base = { path: input.path, commitSha: this.review.commitSha, blobSha: file.content.blobSha };

        if ('file' === input.kind) {
            return { ...base, kind: 'file', range: null, symbol: null, snapshot: null };
        }
        if ('symbol' === input.kind) {
            const symbol = this.#symbols[input.path]?.find(({ fqn }) => fqn === input.symbol.fqn);
            const language = file.content.language;
            if (undefined === symbol || null === language) {
                throw new Error(`The file ${input.path} has no symbol ${input.symbol.fqn}.`);
            }

            return { ...base, kind: 'symbol', range: symbol.range, symbol: { language, kind: symbol.kind, fqn: symbol.fqn }, snapshot: snapshot(file.lines, symbol.range) };
        }

        return { ...base, kind: input.kind, range: input.range, symbol: null, snapshot: snapshot(file.lines, input.range) };
    }

    #blame(content: FileContent, lineCount: number): Blame {
        const commit = { sha: this.review.commitSha, authorName: 'Ada Lovelace', authoredAt: now(-30 * 86_400), summary: 'Add the file' };
        const own = this.blames.get(content.path) ?? { ignoreRevsFile: false, ranges: 0 === lineCount ? [] : [{ startLine: 1, endLine: lineCount, commitSha: commit.sha }], commits: 0 === lineCount ? [] : [commit] };

        return { reviewId: this.id, commitSha: this.review.commitSha, path: content.path, blobSha: content.blobSha, ...own };
    }

    #tree(dir: string): TreeEntry[] {
        const prefix = '' === dir ? '' : `${dir}/`;
        const directories = new Set<string>();
        const files: string[] = [];
        for (const path of this.#files.keys()) {
            const rest = path.startsWith(prefix) ? path.slice(prefix.length) : null;
            if (null !== rest) {
                const slash = rest.indexOf('/');
                if (-1 === slash) {
                    files.push(path);
                } else {
                    directories.add(`${prefix}${rest.slice(0, slash)}`);
                }
            }
        }
        const name = (path: string): string => path.slice(path.lastIndexOf('/') + 1);

        return [
            ...[...directories].sort().map((path): TreeEntry => ({ path, name: name(path), type: 'directory', mode: '040000', sha: sha1(path), sizeBytes: null })),
            ...files.sort().map((path): TreeEntry => {
                const { content } = this.#file(path);

                return { path, name: name(path), type: 'file', mode: '100644', sha: content.blobSha, sizeBytes: content.sizeBytes };
            }),
        ];
    }

    #presentReview(): Review {
        const count = (match: (request: Request) => boolean): number => this.requests.filter(match).length;
        const byStatus = Object.fromEntries((['open', 'in_progress', 'ready_for_review', 'verified', 'reopened', 'wont_do'] as const).map((status) => [status, count((request) => request.status === status)]));
        const byAwaiting = Object.fromEntries((['agent', 'user', 'none'] as const).map((awaiting) => [awaiting, count((request) => request.awaiting === awaiting)]));
        const counts = { total: this.requests.length, byStatus, byAwaiting, outdated: count((request) => true === request.outdated) } as Review['counts'];

        return { ...this.review, counts, lastEventSeq: this.#seq };
    }

    #closedError(): MockResponse | null {
        return 'closed' === this.review.state ? apiError(409, 'review_closed', `Review ${this.id} is closed.`, { reviewId: this.id, closedAt: this.review.closedAt }) : null;
    }

    #register(api: MockApi): void {
        const ours = (handler: (call: ApiCall) => MockResponse) => (call: ApiCall) => (call.params.reviewId === this.id && !this.deleted ? handler(call) : undefined);
        const base = '/api/v1/reviews/:reviewId';

        api.on('GET', base, ours(() => json('review.schema.json', this.#presentReview())));
        api.on('GET', `${base}/drafts`, ours(() => json('lists.schema.json#/$defs/DraftList', { reviewId: this.id, items: this.drafts })));
        api.on('GET', `${base}/viewed-files`, ours(() => this.#viewedFiles()));
        api.on('PUT', `${base}/viewed-files`, ours(({ body }) => {
            const closed = this.#closedError();
            if (null !== closed) {
                return closed;
            }
            const { path, viewed } = body as ViewedFileInput;
            this.viewedPaths = viewed ? [...new Set([...this.viewedPaths, path])] : this.viewedPaths.filter((known) => known !== path);

            return this.#viewedFiles();
        }));
        api.on('GET', `${base}/tree`, ours(({ query }) => {
            const dir = query.get('dir') ?? '';

            return json('lists.schema.json#/$defs/TreeListing', { reviewId: this.id, commitSha: this.review.commitSha, dir, recursive: false, items: this.#tree(dir), nextCursor: null });
        }));
        api.on('GET', `${base}/file`, ours(({ query }) => this.#withFile(query, ({ content }) => json('file-content.schema.json', content))));
        api.on('GET', `${base}/raw`, ours(({ query }) => this.#withFile(query, ({ bytes }) => text(bytes))));
        api.on('GET', `${base}/blame`, ours(({ query }) => this.#withFile(query, ({ content, lines }) => (content.renderable ? json('blame.schema.json', this.#blame(content, lines.length)) : apiError(422, 'invalid_input', 'Must name a renderable file.', { violations: [{ field: 'path', message: 'Must name a renderable file.' }] })))));
        api.on('GET', `${base}/symbols`, ours(({ query }) =>
            this.#withFile(query, ({ content }) => {
                const language = SYMBOL_LANGUAGES.has(content.language) ? content.language : null;

                return json('lists.schema.json#/$defs/SymbolList', { reviewId: this.id, path: content.path, blobSha: content.blobSha, language, parseError: false, items: null === language ? [] : (this.#symbols[content.path] ?? []) });
            }),
        ));
        api.on('POST', `${base}/requests`, ours(({ body }) => {
            const { anchor, body: comment, requireApproval } = body as OpenRequestInput;

            return this.#closedError() ?? json('request.schema.json', this.#present(this.#openRequest(anchor ?? null, comment, null, requireApproval ?? false)), 201);
        }));
        api.on('POST', `${base}/drafts`, ours(({ body }) => this.#closedError() ?? json('draft.schema.json', this.#createDraft(body as CreateDraftInput), 201)));
        api.on('POST', `${base}/drafts/submit`, ours(({ body }) => this.#closedError() ?? this.#submitDrafts(body as SubmitDraftsInput)));
        api.on('POST', `${base}/close`, ours(({ body }) => {
            const closed = this.#closedError();
            if (null !== closed) {
                return closed;
            }
            this.close((body as CloseReviewInput).note ?? null);

            return json('review.schema.json', this.#presentReview());
        }));
        api.on('DELETE', base, ours(() => {
            this.deleted = true;

            return noContent();
        }));
        api.on('PATCH', '/api/v1/drafts/:draftId', (call) =>
            this.#withDraft(call, (draft) => {
                const closed = this.#closedError();
                if (null !== closed) {
                    return closed;
                }
                const { body, requireApproval } = call.body as UpdateDraftInput;
                if (null != requireApproval && 'reply' === draft.kind) {
                    return apiError(422, 'invalid_input', 'Only a new request asks for a plan first.', { violations: [{ field: 'requireApproval', message: 'Only a new request asks for a plan first.' }] });
                }
                const updated = { ...draft, body, requireApproval: requireApproval ?? draft.requireApproval, updatedAt: now() };
                this.drafts = this.drafts.map((known) => (known.id === draft.id ? updated : known));

                return json('draft.schema.json', updated);
            }),
        );
        // D4 also works on a closed review.
        api.on('DELETE', '/api/v1/drafts/:draftId', (call) =>
            this.#withDraft(call, (draft) => {
                this.drafts = this.drafts.filter((known) => known.id !== draft.id);

                return noContent();
            }),
        );
    }

    #registerRequests(api: MockApi): void {
        api.on('GET', '/api/v1/reviews/:reviewId/requests', ({ params, query }) => (params.reviewId === this.id && !this.deleted ? this.#requestPage(query) : undefined));
        api.on('GET', '/api/v1/requests/:requestId', ({ params }) => {
            const { requestId = '' } = params;

            return this.#owns(requestId) ? json('request.schema.json', this.#present(this.find(requestId))) : undefined;
        });
        api.on('POST', '/api/v1/requests/:requestId/:command', ({ params, body }) => {
            const { requestId = '', command = '' } = params;
            if (!this.#owns(requestId)) {
                return undefined;
            }
            const current = this.#present(this.find(requestId));
            const action = COMMAND_ACTIONS[command] ?? command;
            const decision = body as Partial<ApproveProposalInput>;
            if ('pending' === current.proposal?.state && ['approve-proposal', 'reject-proposal'].includes(command) && decision.proposalEventId !== current.proposal.eventId) {
                return apiError(422, 'invalid_input', 'Is not the current proposal.', { violations: [{ field: 'proposalEventId', message: 'Is not the current proposal.' }] });
            }

            if (!current.allowedActions.includes(action)) {
                return (
                    this.#closedError() ??
                    apiError(409, 'invalid_transition', `The request is ${current.status}.`, { requestId, action, status: current.status, claimedBy: current.claimedBy, allowedActions: current.allowedActions })
                );
            }

            return this.#commandResult(this.command(requestId, command, body), 'comments' === command ? 201 : 200);
        });
    }

    /** Q1 with the filters that the request list sends. */
    #requestPage(query: URLSearchParams): MockResponse {
        const list = (name: string): string[] | null => query.get(name)?.split(',') ?? null;
        const [statuses, awaiting, path, outdated] = [list('status'), list('awaiting'), query.get('path'), query.get('outdated')];
        const items = this.requests
            .filter((request) => (null === statuses || statuses.includes(request.status)) && (null === awaiting || awaiting.includes(request.awaiting)))
            .filter((request) => (null === path || true === request.anchor?.path.startsWith(path)) && (null === outdated || request.outdated === ('true' === outdated)))
            .map(requestSummary);

        return json('lists.schema.json#/$defs/RequestPage', { reviewId: this.id, reviewState: this.review.state, total: items.length, items, nextCursor: null });
    }

    #viewedFiles(): MockResponse {
        return json('lists.schema.json#/$defs/ViewedFiles', { reviewId: this.id, paths: this.viewedPaths });
    }

    #withDraft(call: ApiCall, handler: (draft: Draft) => MockResponse): MockResponse | undefined {
        const draft = this.drafts.find(({ id }) => id === call.params.draftId);

        return undefined === draft ? undefined : handler(draft);
    }

    #createDraft(input: CreateDraftInput): Draft {
        const createdAt = now();
        const base = { id: newId(), reviewId: this.id, body: input.body, requireApproval: 'new_request' === input.kind && true === input.requireApproval, createdAt, updatedAt: createdAt };
        let draft: Draft;
        if ('reply' === input.kind) {
            draft = { ...base, kind: 'reply', anchor: null, requestId: this.find(input.requestId).id };
        } else {
            const anchor = input.anchor ?? null;
            draft = { ...base, kind: 'new_request', anchor: null === anchor ? null : this.#anchor(anchor), requestId: null };
            this.#draftAnchors.set(draft.id, anchor);
        }
        this.drafts.push(draft);

        return draft;
    }

    #submitDrafts(input: SubmitDraftsInput): MockResponse {
        if (0 === this.drafts.length) {
            return apiError(422, 'invalid_input', 'There are no drafts to submit.', { violations: [{ field: '', message: 'There are no drafts to submit.' }] });
        }
        if (this.drafts.some(({ kind }) => 'reply' === kind)) {
            throw new Error('The fake submits new-request drafts only.');
        }
        const batchId = newId();
        const opened = this.drafts.map((draft) => this.#openRequest(this.#draftAnchors.get(draft.id) ?? null, draft.body, batchId, draft.requireApproval));
        this.drafts = [];

        return json('batch-submission.schema.json', { batchId, reviewId: this.id, summary: input.summary ?? null, submittedAt: now(), seq: ++this.#seq, openedRequests: opened.map(requestSummary), replies: [] }, 201);
    }
}
