import type { Placement } from 'react-joyride';
import type { KnownTour } from '../api/types/common';
import { stickyHeaderPx } from '../code/scrollTarget';

export type TourStep = {
    title: string;
    text: string;
    /** Called at each position update. */
    target: () => HTMLElement | null;
    placement: Placement;
    /** The step links to Settings. */
    settingsLink?: boolean;
};

/** A part that can start. `line` is the line of the `file` steps. */
export type TourStart = { line: number | null };

const element = (selector: string) => (): HTMLElement | null => document.querySelector<HTMLElement>(selector);

/** The open shadow root of the `@pierre/diffs` file view. */
export function codeRoot(): ShadowRoot | null {
    return document.querySelector('diffs-container')?.shadowRoot ?? null;
}

/**
 * The first line whose number lies in the window below the stuck file header. Null before the view has rows.
 */
function firstVisibleLine(): number | null {
    const headerPx = stickyHeaderPx();
    const numbers = codeRoot()?.querySelectorAll<HTMLElement>('[data-column-number]') ?? [];

    for (const number of numbers) {
        const { top, bottom } = number.getBoundingClientRect();
        if (top >= headerPx && bottom <= window.innerHeight) {
            return Number(number.dataset.columnNumber);
        }
    }

    return null;
}

/** Whether the part can start: the directory table, or the first row of the code. */
export function tourStart(part: KnownTour): TourStart | null {
    if ('code_browser' === part) {
        return null === element('table[aria-label="Folders and files"]')() ? null : { line: null };
    }
    const line = firstVisibleLine();

    return null === line ? null : { line };
}

export function codeBrowserSteps(activeToken: boolean | null | undefined): TourStep[] {
    return [
        { title: 'File tree', text: 'Browse the files of the pinned commit.', target: element('[data-testid="file-tree"]'), placement: 'right-start' },
        { title: 'Folders and files', text: 'Open a file here or in the tree.', target: element('table[aria-label="Folders and files"]'), placement: 'bottom-start' },
        { title: 'Quick open', text: 'Find a file or a symbol by name, with Cmd+K or Ctrl+K.', target: element('[data-tour="quick-open"]'), placement: 'bottom' },
        { title: 'Requests', text: 'Each comment is a change request. Follow them here.', target: element('nav[aria-label="Review"] a[href$="/requests"]'), placement: 'bottom-start' },
        { title: 'Agent prompts', text: 'Give the review to your agent.', target: element('[data-tour="agent-prompts"]'), placement: 'bottom-end', settingsLink: false === activeToken },
    ];
}

/**
 * Go to declaration resolves names in PHP files, which have the input. In other files the step points at the code of
 * `line`.
 */
export function fileSteps(line: number): TourStep[] {
    const declaration = element('input[aria-label="Go to declaration"]');

    return [
        {
            title: 'Comment on code',
            text: 'Click + to comment. Drag over line numbers for a range.',
            target: () => codeRoot()?.querySelector<HTMLElement>(`[data-column-number="${line}"]`) ?? null,
            placement: 'right',
        },
        { title: 'Symbols', text: 'Find a symbol in this file.', target: element('[data-tour="symbols"]'), placement: 'bottom' },
        {
            title: 'Go to declaration',
            text: 'Cmd-click or Ctrl-click a name to go to its declaration. Back returns.',
            target: () => declaration() ?? codeRoot()?.querySelector<HTMLElement>(`[data-line="${line}"]`) ?? null,
            placement: 'bottom',
        },
    ];
}
