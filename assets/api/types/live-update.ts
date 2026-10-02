import type { EventSeq, Ulid } from './common';

export type EventsUpdate = {
    type: 'events';
    reviewId: Ulid;
    /** seq of the last event that the transaction wrote. */
    seq: EventSeq;
    /** [] when only review events were written. */
    requestIds: Ulid[];
};

export type OutdatedUpdate = {
    type: 'outdated';
    reviewId: Ulid;
    /** Requests whose outdated flag changed. */
    requestIds: Ulid[];
};

export type IndexUpdate = {
    type: 'index';
    reviewId: Ulid;
};

export type RepositoryUpdate = {
    type: 'repository';
    repositoryId: Ulid;
};

export type RepositoryDeletedUpdate = {
    type: 'repository_deleted';
    repositoryId: Ulid;
};

export type CandidatesChangedUpdate = {
    type: 'candidates_changed';
};

/** IDs only: reload the data through REST. `type` is an open set, so ignore an unknown one. */
export type LiveUpdate =
    | EventsUpdate
    | OutdatedUpdate
    | IndexUpdate
    | RepositoryUpdate
    | RepositoryDeletedUpdate
    | CandidatesChangedUpdate;
