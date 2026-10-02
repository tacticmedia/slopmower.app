import { useContext, useEffect, useRef, useState } from 'react';
import { type DataRouter, UNSAFE_DataRouterContext, useRouteLoaderData } from 'react-router';
import { ApiError, apiGet, apiWrite } from '../api/client';
import type { MercureAuthorization } from '../api/types/lists';
import type { LiveUpdate } from '../api/types/live-update';
import type { Request as ReviewRequest } from '../api/types/request';
import type { Review } from '../api/types/review';
import type { UpdatesPage } from '../api/types/updates-page';
import { type EventPositions, eventPositions } from './eventPositions';

/** The only variable of the review selector in the subscriber JWT. */
const REVIEW_VARIABLE = '{reviewId}';

const REAUTHORIZE_BEFORE_EXPIRY_MS = 60_000;

/** The first retry after a failure. Also the shortest wait before A3 runs again, so a skewed client clock cannot loop. */
const RETRY_DELAY_MS = 10_000;

const MAX_RETRY_DELAY_MS = 300_000;

/** `EventSource.CLOSED`: the browser gave up reconnecting, for example after a 401 of the hub. */
const CLOSED = 2;

/** The members of EventSource that the subscription uses. */
export type LiveEventSource = {
    onopen: ((event: Event) => void) | null;
    onmessage: ((event: MessageEvent) => void) | null;
    onerror: ((event: Event) => void) | null;
    readonly readyState: number;
    close(): void;
};

export type LiveConnection = {
    authorize: () => Promise<MercureAuthorization>;
    open: (url: string) => LiveEventSource;
    /** Resolves when no navigation and no fetcher runs. */
    routerIdle: () => Promise<void>;
    revalidate: () => Promise<void>;
    readUpdates: (reviewId: string, cursor: number) => Promise<UpdatesPage>;
    /** Whether the EventSource of the current topics is open. */
    connectionChanged?: (open: boolean) => void;
};

/**
 * `hubUrl` with the repositories topic, and the topic of the open review.
 */
export function subscriptionUrl(authorization: MercureAuthorization, reviewId: string | null): string {
    const topics = authorization.topics.filter((topic) => !topic.includes(REVIEW_VARIABLE));
    const reviewSelector = authorization.topics.find((topic) => topic.includes(REVIEW_VARIABLE));
    if (null !== reviewId && undefined !== reviewSelector) {
        topics.push(reviewSelector.replace(REVIEW_VARIABLE, reviewId));
    }

    return `${authorization.hubUrl}?${topics.map((topic) => `topic=${encodeURIComponent(topic)}`).join('&')}`;
}

/**
 * The one EventSource of the UI (D29), as docs/specs/webhooks-and-live-updates.md section 3 describes it.
 */
export class LiveSubscription {
    readonly #connection: LiveConnection;
    readonly #positions: EventPositions;
    #authorization: MercureAuthorization | null = null;
    #source: LiveEventSource | null = null;
    #reviewId: string | null = null;
    #started = false;
    #stopped = false;
    #reconnecting = false;
    #failures = 0;
    #revalidating = false;
    /** The highest seq per review ID of the events updates since the last revalidation started. */
    readonly #pendingEvents = new Map<string, number>();
    /** Whether an update since the last revalidation started needs a revalidation whatever the seqs. */
    #pendingOther = false;
    #timer: ReturnType<typeof setTimeout> | undefined;

    constructor(connection: LiveConnection, positions: EventPositions = eventPositions) {
        this.#connection = connection;
        this.#positions = positions;
    }

    /**
     * Subscribes to the topic of the review, or of no review. The first call authorizes.
     */
    watch(reviewId: string | null): void {
        if (this.#started && reviewId === this.#reviewId) {
            return;
        }
        this.#reviewId = reviewId;

        if (!this.#started) {
            this.#started = true;
            void this.#authorize();
        } else if (null !== this.#authorization) {
            this.#open();
        }
    }

    stop(): void {
        this.#stopped = true;
        clearTimeout(this.#timer);
        this.#source?.close();
        this.#source = null;
        this.#connection.connectionChanged?.(false);
    }

    /**
     * Reads the events after `lastSeq` of the open review, then revalidates once. The UI does not rely on
     * `Last-Event-ID` replay.
     */
    async catchUp(): Promise<void> {
        try {
            await this.#readMissedEvents();
        } catch {
            // The revalidation reloads what the read missed.
        }

        this.#requestRevalidation(null);
    }

    async #readMissedEvents(): Promise<void> {
        const reviewId = this.#reviewId;
        let cursor = null === reviewId ? undefined : this.#positions.last(reviewId);
        if (null === reviewId || undefined === cursor) {
            return;
        }

        for (let hasMore = true; hasMore; ) {
            const page = await this.#connection.readUpdates(reviewId, cursor);
            cursor = page.cursor;
            hasMore = page.hasMore;
        }
        this.#positions.seen(reviewId, cursor);
    }

    async #authorize(): Promise<void> {
        try {
            this.#authorization = await this.#connection.authorize();
        } catch (error) {
            if (error instanceof ApiError && 401 === error.status) {
                // The session ended. The next navigation leads to the sign-in page.
                this.stop();
            } else {
                this.#retry();
            }

            return;
        }

        if (this.#stopped) {
            return;
        }
        if (null === this.#source || CLOSED === this.#source.readyState) {
            this.#open();
        }
        this.#schedule(Math.max(RETRY_DELAY_MS, Date.parse(this.#authorization.expiresAt) - Date.now() - REAUTHORIZE_BEFORE_EXPIRY_MS));
    }

    /**
     * Doubles the wait after each failure in a row, up to five minutes. An open EventSource resets it.
     */
    #retry(): void {
        this.#schedule(Math.min(MAX_RETRY_DELAY_MS, RETRY_DELAY_MS * 2 ** this.#failures));
        this.#failures += 1;
    }

    #schedule(delayMs: number): void {
        clearTimeout(this.#timer);
        if (!this.#stopped) {
            this.#timer = setTimeout(() => void this.#authorize(), delayMs);
        }
    }

    #open(): void {
        if (null === this.#authorization) {
            return;
        }
        this.#source?.close();
        this.#connection.connectionChanged?.(false);
        const source = this.#connection.open(subscriptionUrl(this.#authorization, this.#reviewId));
        source.onmessage = (event) => this.#receive(event);
        source.onopen = () => this.#opened(source);
        source.onerror = () => this.#failed(source);
        this.#source = source;
    }

    #opened(source: LiveEventSource): void {
        if (source !== this.#source) {
            return;
        }
        this.#failures = 0;
        this.#connection.connectionChanged?.(true);
        if (this.#reconnecting) {
            this.#reconnecting = false;
            void this.catchUp();
        }
    }

    #failed(source: LiveEventSource): void {
        if (source !== this.#source) {
            return;
        }
        this.#connection.connectionChanged?.(false);
        this.#reconnecting = true;
        if (CLOSED === source.readyState) {
            this.#retry();
        }
    }

    #receive(event: MessageEvent): void {
        const update = parseUpdate(event.data);
        if (this.#stopped || null === update) {
            return;
        }

        switch (update.type) {
            case 'events':
                if (update.reviewId === this.#reviewId && update.seq > (this.#positions.last(update.reviewId) ?? -1)) {
                    this.#positions.seen(update.reviewId, update.seq);
                    this.#requestRevalidation(update);
                }

                return;
            case 'outdated':
            case 'index':
                if (update.reviewId === this.#reviewId) {
                    this.#requestRevalidation(null);
                }

                return;
            case 'repository':
            case 'repository_deleted':
            case 'candidates_changed':
                this.#requestRevalidation(null);

                return;
        }
    }

    /**
     * @param events Null for a reason to revalidate whatever the seqs
     */
    #requestRevalidation(events: { reviewId: string; seq: number } | null): void {
        if (this.#stopped) {
            return;
        }
        if (null === events) {
            this.#pendingOther = true;
        } else {
            this.#pendingEvents.set(events.reviewId, Math.max(events.seq, this.#pendingEvents.get(events.reviewId) ?? events.seq));
        }
        if (!this.#revalidating) {
            this.#revalidating = true;
            void this.#revalidate();
        }
    }

    /**
     * One revalidation at a time, after the router's own work. Updates during a revalidation cause one more after it.
     * Updates that only report own writes, which the router revalidated after their actions, cause none.
     */
    async #revalidate(): Promise<void> {
        try {
            while ((this.#pendingOther || this.#pendingEvents.size > 0) && !this.#stopped) {
                await this.#connection.routerIdle();
                const covered = !this.#pendingOther && [...this.#pendingEvents].every(([reviewId, seq]) => this.#positions.covers(reviewId, seq));
                this.#pendingOther = false;
                this.#pendingEvents.clear();
                if (!covered && !this.#stopped) {
                    await this.#connection.revalidate();
                }
            }
        } finally {
            this.#revalidating = false;
        }
    }
}

/**
 * Null for data that is not a JSON object with a string `type`. A known `type` is trusted to carry its fields.
 */
function parseUpdate(data: unknown): LiveUpdate | null {
    try {
        const update: unknown = 'string' === typeof data ? JSON.parse(data) : null;

        return 'object' === typeof update && null !== update && 'type' in update && 'string' === typeof update.type ? (update as LiveUpdate) : null;
    } catch {
        return null;
    }
}

/**
 * Reads the router itself, not the rendered state, which RouterProvider updates in a transition and which can lag a
 * started fetcher. A revalidation during a fetcher action makes the router drop the redirect of that action.
 */
function routerIdle(router: DataRouter): Promise<void> {
    const idle = (): boolean => 'idle' === router.state.navigation.state && [...router.state.fetchers.values()].every((fetcher) => 'idle' === fetcher.state);

    return new Promise((resolve) => {
        if (idle()) {
            resolve();

            return;
        }
        const unsubscribe = router.subscribe(() => {
            if (idle()) {
                unsubscribe();
                resolve();
            }
        });
    });
}

/**
 * Mounted once, by RootLayout. The open review comes from the route data of the review layout or the request detail.
 *
 * @returns Whether the EventSource of the current topics is open
 */
export function useLiveRevalidation(): boolean {
    const router = useContext(UNSAFE_DataRouterContext)?.router;
    if (undefined === router) {
        throw new Error('useLiveRevalidation needs a data router.');
    }
    const review = useRouteLoaderData<Review>('review');
    const request = useRouteLoaderData<ReviewRequest>('request');
    const reviewId = review?.id ?? request?.reviewId ?? null;
    const [connected, setConnected] = useState(false);
    const subscriptionRef = useRef<LiveSubscription | null>(null);

    useEffect(() => {
        const subscription = new LiveSubscription({
            authorize: () => apiWrite<MercureAuthorization>('POST', '/api/v1/mercure/authorize'),
            open: (url) => new EventSource(url, { withCredentials: true }),
            routerIdle: () => routerIdle(router),
            revalidate: () => router.revalidate(),
            readUpdates: (id, cursor) => apiGet<UpdatesPage>(`/api/v1/reviews/${encodeURIComponent(id)}/updates?cursor=${cursor}&includeOwn=true`),
            connectionChanged: setConnected,
        });
        const catchUpWhenVisible = (): void => {
            if ('visible' === document.visibilityState) {
                void subscription.catchUp();
            }
        };
        subscriptionRef.current = subscription;
        document.addEventListener('visibilitychange', catchUpWhenVisible);

        return () => {
            document.removeEventListener('visibilitychange', catchUpWhenVisible);
            subscription.stop();
        };
    }, [router]);

    useEffect(() => {
        subscriptionRef.current?.watch(reviewId);
    }, [reviewId]);

    useEffect(() => {
        if (undefined !== review) {
            eventPositions.seen(review.id, review.lastEventSeq);
        }
    }, [review]);

    return connected;
}
