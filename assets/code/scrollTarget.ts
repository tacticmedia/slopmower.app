/** A vertical span in page or viewport coordinates. */
export type Span = { top: number; bottom: number };

/** The bands at the top and the bottom of the viewport where a drag selection scrolls the window. */
const DRAG_SCROLL_BAND_PX = 48;

const DRAG_SCROLL_MAXIMUM_STEP_PX = 40;

/** The input of a user who takes over the scroll. An automatic scroll stops at the first one. */
export const USER_INPUT_EVENTS = ['wheel', 'touchstart', 'pointerdown', 'keydown'] as const;

/**
 * The window scroll position that centres `span` (page coordinates) in the part of the viewport below the sticky
 * header. A span taller than that part starts right below the header.
 */
export function centredScrollTop(span: Span, headerPx: number, viewportPx: number): number {
    const free = Math.max(viewportPx - headerPx - (span.bottom - span.top), 0);

    return Math.max(span.top - headerPx - free / 2, 0);
}

/** Whether `span` (viewport coordinates) lies in the part of the viewport below the sticky header. */
export function isBelowHeader(span: Span, headerPx: number, viewportPx: number): boolean {
    return span.top >= headerPx && span.bottom <= viewportPx;
}

/** The height that the sticky file header covers at the top of the window, from `scroll-padding-top` of the root. */
export function stickyHeaderPx(): number {
    const padding = Number.parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop);

    return Number.isFinite(padding) ? padding : 0;
}

/**
 * The window scroll of one frame for a drag at `pointerY` in a viewport from `topPx` to `bottomPx`: negative upwards,
 * zero outside the bands, and faster the deeper the pointer is in a band or past it.
 */
export function dragScrollStepPx(pointerY: number, topPx: number, bottomPx: number): number {
    const depth = pointerY < topPx + DRAG_SCROLL_BAND_PX ? pointerY - topPx - DRAG_SCROLL_BAND_PX : Math.max(pointerY - bottomPx + DRAG_SCROLL_BAND_PX, 0);

    return Math.sign(depth) * Math.min(Math.ceil(Math.abs(depth) / 2), DRAG_SCROLL_MAXIMUM_STEP_PX);
}
