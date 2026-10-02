import type { Action } from './common';
import type { Event } from './event';
import type { RequestSummary } from './request-summary';

export type RequestCommandResult = {
    request: RequestSummary;
    allowedActions: Action[];
    /** Empty for a no-op. */
    events: Event[];
};
