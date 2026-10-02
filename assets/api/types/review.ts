import type { Actor } from './actor';
import type { Awaiting, CommitSha, DateTime, EventSeq, IndexState, RequestStatus, Ulid } from './common';

export type IndexStatus = {
    state: IndexState;
    /** Null until the path list is written. */
    filesTotal: number | null;
    filesDone: number;
    symbolsTotal: number;
    startedAt: DateTime | null;
    finishedAt: DateTime | null;
    error: string | null;
};

export type RequestCounts = {
    total: number;
    byStatus: Record<RequestStatus, number>;
    byAwaiting: Record<Awaiting, number>;
    outdated: number;
};

type StateFields =
    | { state: 'open'; closedAt: null; closedBy: null; closeNote: null }
    | { state: 'closed'; closedAt: DateTime; closedBy: Actor; closeNote: string | null };

export type Review = {
    id: Ulid;
    repositoryId: Ulid;
    repositoryName: string;
    title: string;
    /** As given at start: branch, tag, or SHA. */
    ref: string;
    commitSha: CommitSha;
    startedBy: Actor;
    createdAt: DateTime;
    index: IndexStatus;
    counts: RequestCounts;
    lastEventSeq: EventSeq;
} & StateFields;
