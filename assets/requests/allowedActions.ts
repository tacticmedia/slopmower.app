import type { Action } from '../api/types/common';

/** The user buttons of the request detail, in display order. */
const REQUEST_BUTTONS = ['verify', 'reopen', 'set_plan_requirement', 'mark_wont_do', 'release'] as const;

export type RequestButton = (typeof REQUEST_BUTTONS)[number];

const BUTTON_LABELS: Record<Exclude<RequestButton, 'set_plan_requirement'>, string> = {
    verify: 'Resolve conversation',
    reopen: 'Reopen',
    mark_wont_do: "Close as won't do",
    release: 'Unassign',
};

/** `set_plan_requirement` names the value that a click sets. */
export function buttonLabel(button: RequestButton, requireApproval: boolean): string {
    if ('set_plan_requirement' === button) {
        return requireApproval ? "Don't ask for a plan" : 'Ask for a plan first';
    }

    return BUTTON_LABELS[button];
}

/**
 * `reply` is the reply box, not a button. Agent actions and actions of a newer server have no button.
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
