import type { Action } from '../api/types/common';

/** The user buttons of the action bar of the request detail, in display order. */
const REQUEST_BUTTONS = ['verify', 'reopen', 'mark_wont_do', 'release'] as const;

export type RequestButton = (typeof REQUEST_BUTTONS)[number];

export const REQUEST_BUTTON_LABELS: Record<RequestButton, string> = {
    verify: 'Resolve conversation',
    reopen: 'Reopen',
    mark_wont_do: "Close as won't do",
    release: 'Unassign',
};

/**
 * `reply` is the reply box and `set_plan_requirement` the plan toggle of the sidebar. Agent actions and actions of a newer
 * server have no button.
 */
export function requestButtons(allowedActions: readonly Action[]): RequestButton[] {
    return REQUEST_BUTTONS.filter((button) => allowedActions.includes(button));
}

/** The decisions on a pending suggestion, in display order. */
const SUGGESTION_BUTTONS = ['accept_suggestion', 'dismiss_suggestion'] as const;

export type SuggestionButton = (typeof SUGGESTION_BUTTONS)[number];

export function suggestionButtons(allowedActions: readonly Action[]): SuggestionButton[] {
    return SUGGESTION_BUTTONS.filter((button) => allowedActions.includes(button));
}
