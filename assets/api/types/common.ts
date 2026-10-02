/** Accepts unknown values that a newer v1 server may send. */
export type OpenSet<Known extends string> = Known | (string & {});

export type Ulid = string;
export type CommitSha = string;
export type CommitShaPrefix = string;
export type ObjectSha = string;
export type RepoPath = string;
/** RFC 3339, UTC, `Z` suffix. */
export type DateTime = string;
export type Markdown = string;
export type Cursor = string;
export type EventSeq = number;

export const REPOSITORY_SOURCES = ['github', 'local'] as const;
export type RepositorySource = (typeof REPOSITORY_SOURCES)[number];

export const REPOSITORY_ACCESSES = ['active', 'removed'] as const;
export type RepositoryAccess = (typeof REPOSITORY_ACCESSES)[number];

export const REVIEW_STATES = ['open', 'closed'] as const;
export type ReviewState = (typeof REVIEW_STATES)[number];

export const INDEX_STATES = ['pending', 'running', 'ready', 'failed'] as const;
export type IndexState = (typeof INDEX_STATES)[number];

export const REQUEST_STATUSES = ['open', 'in_progress', 'ready_for_review', 'verified', 'reopened', 'wont_do'] as const;
export type RequestStatus = (typeof REQUEST_STATUSES)[number];

export const AWAITING_VALUES = ['agent', 'user', 'none'] as const;
export type Awaiting = (typeof AWAITING_VALUES)[number];

export const RESOLUTIONS = ['fixed', 'wont_do'] as const;
export type Resolution = (typeof RESOLUTIONS)[number];

export const ANCHOR_KINDS = ['line', 'range', 'symbol', 'file'] as const;
export type AnchorKind = (typeof ANCHOR_KINDS)[number];

export const ACTOR_KINDS = ['user', 'agent', 'system'] as const;
export type ActorKind = (typeof ACTOR_KINDS)[number];

export const COMMIT_LINK_STATES = ['pending', 'available'] as const;
export type CommitLinkState = (typeof COMMIT_LINK_STATES)[number];

export const LOCATION_STATUSES = ['exact', 'shifted', 'changed', 'orphaned'] as const;
export type LocationStatus = (typeof LOCATION_STATUSES)[number];

export const ORPHAN_REASONS = ['file_deleted', 'region_deleted', 'ambiguous', 'content_not_found'] as const;
export type OrphanReason = (typeof ORPHAN_REASONS)[number];

export const SYMBOL_KINDS = [
    'class',
    'interface',
    'trait',
    'enum',
    'function',
    'method',
    'property',
    'class_constant',
    'enum_case',
    'constant',
] as const;
export type KnownSymbolKind = (typeof SYMBOL_KINDS)[number];
export type SymbolKind = OpenSet<KnownSymbolKind>;

export const ACTIONS = [
    'reply',
    'claim',
    'release',
    'link_commits',
    'unlink_commit',
    'mark_ready_for_review',
    'mark_wont_do',
    'verify',
    'reopen',
] as const;
export type KnownAction = (typeof ACTIONS)[number];
export type Action = OpenSet<KnownAction>;

export const EVENT_TYPES = [
    'review.started',
    'review.closed',
    'request.opened',
    'request.commented',
    'request.claimed',
    'request.released',
    'commit.linked',
    'commit.unlinked',
    'commit.found',
    'request.ready_for_review',
    'request.wont_do',
    'request.verified',
    'request.reopened',
    'batch.submitted',
] as const;
export type KnownEventType = (typeof EVENT_TYPES)[number];
export type EventType = OpenSet<KnownEventType>;

export const THREAD_ENTRY_TYPES = [
    'request.opened',
    'request.commented',
    'request.claimed',
    'request.released',
    'commit.linked',
    'commit.unlinked',
    'commit.found',
    'request.ready_for_review',
    'request.wont_do',
    'request.verified',
    'request.reopened',
] as const;
export type KnownThreadEntryType = (typeof THREAD_ENTRY_TYPES)[number];
export type ThreadEntryType = OpenSet<KnownThreadEntryType>;

export const ERROR_CODES = [
    'unauthorized',
    'forbidden',
    'not_found',
    'invalid_input',
    'invalid_transition',
    'review_closed',
    'commits_not_available',
    'commit_not_found',
    'repository_unavailable',
    'internal_error',
] as const;
export type KnownErrorCode = (typeof ERROR_CODES)[number];
export type ErrorCode = OpenSet<KnownErrorCode>;
