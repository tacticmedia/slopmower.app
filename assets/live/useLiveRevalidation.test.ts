import { afterEach, beforeEach, describe, expect, it, type Mock, vi } from 'vitest';
import authorizationFixture from '../../docs/specs/fixtures/lists.mercure-authorization.json';
import eventsUpdate from '../../docs/specs/fixtures/live-update.events.json';
import repositoryUpdate from '../../docs/specs/fixtures/live-update.repository.json';
import { ApiError } from '../api/client';
import type { MercureAuthorization } from '../api/types/lists';
import type { UpdatesPage } from '../api/types/updates-page';
import { EventPositions } from './eventPositions';
import { type LiveConnection, type LiveEventSource, LiveSubscription } from './useLiveRevalidation';

const REVIEW = eventsUpdate.reviewId;
const OTHER_REVIEW = '01K6B3A1B2C3D4E5F6G7H8J9KN';

class FakeEventSource implements LiveEventSource {
    onopen: ((event: Event) => void) | null = null;
    onmessage: ((event: MessageEvent) => void) | null = null;
    onerror: ((event: Event) => void) | null = null;
    readyState = 0;
    readonly url: string;

    constructor(url: string) {
        this.url = url;
    }

    close(): void {
        this.readyState = 2;
    }

    open(): void {
        this.readyState = 1;
        this.onopen?.(new Event('open'));
    }

    /** The browser reconnects by itself. */
    drop(): void {
        this.readyState = 0;
        this.onerror?.(new Event('error'));
    }

    receive(data: unknown): void {
        this.onmessage?.(new MessageEvent('message', { data: 'string' === typeof data ? data : JSON.stringify(data) }));
    }
}

function page(cursor: number, hasMore: boolean): UpdatesPage {
    return { reviewId: REVIEW, reviewState: 'open', cursor, hasMore, awaitingTotal: 0, requests: [], reviewEvents: [] };
}

/** Lets the awaited promises of the subscription settle. */
function settle(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

describe('UseLiveRevalidationTest', () => {
    let sources: FakeEventSource[];
    let revalidate: Mock<() => Promise<void>>;
    let routerIdle: Mock<() => Promise<void>>;
    let readUpdates: Mock<(reviewId: string, cursor: number) => Promise<UpdatesPage>>;
    let connection: LiveConnection;
    let positions: EventPositions;
    let subscription: LiveSubscription;

    async function watching(reviewId: string | null): Promise<FakeEventSource> {
        subscription.watch(reviewId);
        await settle();
        const source = sources.at(-1);
        expect(source).toBeDefined();

        return source as FakeEventSource;
    }

    beforeEach(() => {
        sources = [];
        revalidate = vi.fn(() => Promise.resolve());
        routerIdle = vi.fn(() => Promise.resolve());
        readUpdates = vi.fn();
        positions = new EventPositions();
        connection = {
            authorize: () => Promise.resolve(authorizationFixture),
            open: (url) => {
                const source = new FakeEventSource(url);
                sources.push(source);

                return source;
            },
            routerIdle,
            revalidate,
            readUpdates,
        };
        subscription = new LiveSubscription(connection, positions);
    });

    afterEach(() => {
        subscription.stop();
        vi.useRealTimers();
    });

    it('Opens one EventSource with both topics', async () => {
        const [repositoriesTopic, reviewSelector] = authorizationFixture.topics as [string, string];

        await watching(REVIEW);
        subscription.watch(REVIEW);
        await settle();

        expect(sources.map((source) => source.url)).toEqual([
            `${authorizationFixture.hubUrl}?topic=${encodeURIComponent(repositoriesTopic)}&topic=${encodeURIComponent(reviewSelector.replace('{reviewId}', REVIEW))}`,
        ]);
    });

    it('Updates during a running revalidation cause exactly one more revalidation', async () => {
        const running: Array<() => void> = [];
        revalidate.mockImplementation(() => new Promise<void>((resolve) => running.push(resolve)));
        const source = await watching(REVIEW);
        source.receive(repositoryUpdate);
        await settle();
        expect(revalidate).toHaveBeenCalledTimes(1);

        source.receive(repositoryUpdate);
        source.receive({ type: 'candidates_changed' });
        source.receive(eventsUpdate);
        await settle();
        expect(revalidate).toHaveBeenCalledTimes(1);

        running.shift()?.();
        await settle();
        expect(revalidate).toHaveBeenCalledTimes(2);

        running.shift()?.();
        await settle();
        expect(revalidate).toHaveBeenCalledTimes(2);
    });

    it('An events update with a seq not above lastSeq is ignored', async () => {
        positions.seen(REVIEW, eventsUpdate.seq);
        const source = await watching(REVIEW);

        source.receive(eventsUpdate);
        await settle();
        expect(revalidate).not.toHaveBeenCalled();

        source.receive({ ...eventsUpdate, seq: eventsUpdate.seq + 1 });
        await settle();
        expect(revalidate).toHaveBeenCalledTimes(1);
    });

    it('The update of an own write that arrives before the command result causes no second revalidation', async () => {
        const idle: Array<() => void> = [];
        routerIdle.mockImplementation(() => new Promise<void>((resolve) => idle.push(resolve)));
        positions.seen(REVIEW, eventsUpdate.seq);
        const source = await watching(REVIEW);

        source.receive({ ...eventsUpdate, seq: eventsUpdate.seq + 1 });
        await settle();
        // The command result of the fetcher action, which the router revalidates after.
        positions.written(REVIEW, eventsUpdate.seq + 1);
        idle.shift()?.();
        await settle();
        expect(revalidate).not.toHaveBeenCalled();

        source.receive({ ...eventsUpdate, seq: eventsUpdate.seq + 2 });
        await settle();
        idle.shift()?.();
        await settle();
        expect(revalidate).toHaveBeenCalledTimes(1);
    });

    it('An events update of another review causes no revalidation', async () => {
        const source = await watching(REVIEW);

        source.receive({ ...eventsUpdate, reviewId: OTHER_REVIEW });
        await settle();

        expect(revalidate).not.toHaveBeenCalled();
    });

    it('Unknown update types are ignored', async () => {
        const source = await watching(REVIEW);

        source.receive({ type: 'resync', reviewId: REVIEW });
        source.receive('not JSON');
        await settle();

        expect(revalidate).not.toHaveBeenCalled();
    });

    it('After a reconnect, the hook reads updates from lastSeq until hasMore is false', async () => {
        positions.seen(REVIEW, 1800);
        readUpdates.mockResolvedValueOnce(page(1825, true)).mockResolvedValueOnce(page(1850, false));
        const source = await watching(REVIEW);
        source.open();

        source.drop();
        source.open();
        await settle();

        expect(readUpdates.mock.calls).toEqual([
            [REVIEW, 1800],
            [REVIEW, 1825],
        ]);
        expect(revalidate).toHaveBeenCalledTimes(1);
        source.receive({ ...eventsUpdate, seq: 1850 });
        await settle();
        expect(revalidate).toHaveBeenCalledTimes(1);
    });

    it('A failing A3 retries after 10 s, then 20 s, and a 401 stops the retries', async () => {
        vi.useFakeTimers();
        const authorize = vi
            .fn<() => Promise<MercureAuthorization>>()
            .mockRejectedValueOnce(new ApiError(503, 'internal_error', 'The server is unavailable.', null))
            .mockRejectedValueOnce(new ApiError(503, 'internal_error', 'The server is unavailable.', null))
            .mockRejectedValueOnce(new ApiError(401, 'unauthorized', 'Sign in again.', null));
        subscription = new LiveSubscription({ ...connection, authorize }, positions);

        subscription.watch(REVIEW);
        await vi.advanceTimersByTimeAsync(9_999);
        expect(authorize).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(authorize).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(19_999);
        expect(authorize).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(1);
        expect(authorize).toHaveBeenCalledTimes(3);

        await vi.advanceTimersByTimeAsync(600_000);
        expect(authorize).toHaveBeenCalledTimes(3);
        expect(sources).toEqual([]);
    });
});
