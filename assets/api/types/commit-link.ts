import type { Actor } from './actor';
import type { CommitSha, DateTime, Ulid } from './common';

type StateFields =
    | { state: 'pending'; foundAt: null; subject: null; committedAt: null }
    | { state: 'available'; foundAt: DateTime; subject: string; committedAt: DateTime };

export type CommitLink = {
    commitSha: CommitSha;
    linkedBy: Actor;
    linkedAt: DateTime;
    note: string | null;
    /** Other requests of the same review linked to this commit. */
    otherRequestIds: Ulid[];
} & StateFields;
