import type { Action } from '../api/types/common';

/** The user buttons of the request detail, in display order. */
const REQUEST_BUTTONS = ['verify', 'reopen', 'mark_wont_do', 'release'] as const;

export type RequestButton = (typeof REQUEST_BUTTONS)[number];

export const BUTTON_LABELS: Record<RequestButton, string> = {
    verify: 'Resolve conversation',
    reopen: 'Reopen',
    mark_wont_do: "Close as won't do",
    release: 'Unassign',
};

/**
 * `reply` is the reply box, not a button. Agent actions and actions of a newer server have no button.
 */
export function requestButtons(allowedActions: readonly Action[]): RequestButton[] {
    return REQUEST_BUTTONS.filter((button) => allowedActions.includes(button));
}
