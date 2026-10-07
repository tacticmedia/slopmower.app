/** A vertical span in page or viewport coordinates. */
export type Span = { top: number; bottom: number };

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
