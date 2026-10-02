import type { CommitLinkState, CommitSha } from './common';
import type { Event } from './event';
import type { RequestSummary } from './request-summary';

export type LinkCommitsResult = {
    commitSha: CommitSha;
    state: CommitLinkState;
    requests: RequestSummary[];
    events: Event[];
};
