import type { PullRequestUrl } from './common';
import type { Event } from './event';
import type { RequestSummary } from './request-summary';

export type LinkPullRequestResult = {
    url: PullRequestUrl;
    requests: RequestSummary[];
    events: Event[];
};
