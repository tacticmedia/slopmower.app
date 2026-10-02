import type { Event as ReviewEvent } from '../api/types/event';

/**
 * The event seqs per review ID that the UI has already loaded.
 */
export class EventPositions {
    readonly #last = new Map<string, number>();
    readonly #covered = new Map<string, number>();

    /** `lastSeq`: from events updates, `Review.lastEventSeq`, and the cursor of the updates endpoint. */
    seen(reviewId: string, seq: number): void {
        this.#last.set(reviewId, Math.max(seq, this.#last.get(reviewId) ?? seq));
    }

    /**
     * From a command result. The router revalidates after the action, so this seq needs no revalidation of its own.
     */
    written(reviewId: string, seq: number): void {
        this.seen(reviewId, seq);
        this.#covered.set(reviewId, Math.max(seq, this.#covered.get(reviewId) ?? seq));
    }

    last(reviewId: string): number | undefined {
        return this.#last.get(reviewId);
    }

    covers(reviewId: string, seq: number): boolean {
        return seq <= (this.#covered.get(reviewId) ?? -1);
    }
}

/** Route actions write it outside React. */
export const eventPositions = new EventPositions();

export function rememberEvents(events: readonly ReviewEvent[], positions: EventPositions = eventPositions): void {
    for (const event of events) {
        positions.written(event.reviewId, event.seq);
    }
}
