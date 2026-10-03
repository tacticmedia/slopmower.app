import type { Page } from '@playwright/test';
import type { LiveUpdate } from '../../../assets/api/types/live-update';
import { mercureAuthorization } from './builders';
import { assertMatchesSchema } from './schemas';

declare global {
    interface Window {
        /** Delivers the data to every open EventSource that subscribes to the topic. Returns their count. */
        pushLiveUpdate(data: string, topic: string): number;
    }
}

/**
 * Replaces EventSource before the app loads, because Playwright cannot stream a fulfilled response. A source opens in
 * the next task, as after a successful connection.
 */
export function installFakeEventSource(): void {
    const sources: FakeEventSource[] = [];

    class FakeEventSource extends EventTarget {
        static readonly CONNECTING = 0;
        static readonly OPEN = 1;
        static readonly CLOSED = 2;
        readonly url: string;
        readonly withCredentials: boolean;
        readyState = FakeEventSource.CONNECTING;
        onopen: ((event: Event) => void) | null = null;
        onmessage: ((event: MessageEvent) => void) | null = null;
        onerror: ((event: Event) => void) | null = null;

        constructor(url: string | URL, init?: EventSourceInit) {
            super();
            this.url = String(url);
            this.withCredentials = true === init?.withCredentials;
            sources.push(this);
            setTimeout(() => {
                if (FakeEventSource.CLOSED !== this.readyState) {
                    this.readyState = FakeEventSource.OPEN;
                    this.onopen?.(new Event('open'));
                }
            });
        }

        close(): void {
            this.readyState = FakeEventSource.CLOSED;
        }
    }

    Object.defineProperty(window, 'EventSource', { value: FakeEventSource, configurable: true, writable: true });
    window.pushLiveUpdate = (data, topic) => {
        const subscribed = sources.filter((source) => FakeEventSource.OPEN === source.readyState && new URL(source.url).searchParams.getAll('topic').includes(topic));
        for (const source of subscribed) {
            source.onmessage?.(new MessageEvent('message', { data }));
        }

        return subscribed.length;
    };
}

/** Publishes Mercure updates to the page, on the topics of docs/specs/webhooks-and-live-updates.md section 3. */
export class Live {
    readonly #page: Page;

    constructor(page: Page) {
        this.#page = page;
    }

    async push(update: LiveUpdate): Promise<void> {
        assertMatchesSchema('live-update.schema.json', update);
        const topic = topicOf(update);
        const delivered = await this.#page.evaluate(([data, target]) => window.pushLiveUpdate(data, target), [JSON.stringify(update), topic] as const);

        if (0 === delivered) {
            throw new Error(`No open EventSource of the page subscribes to ${topic}.`);
        }
    }
}

function topicOf(update: LiveUpdate): string {
    const { topics } = mercureAuthorization();
    const reviews = topics.find((topic) => topic.includes('{reviewId}'));
    const repositories = topics.find((topic) => !topic.includes('{reviewId}'));

    if (undefined === reviews || undefined === repositories) {
        throw new Error('The Mercure authorization fixture lacks a topic.');
    }

    return 'reviewId' in update ? reviews.replace('{reviewId}', update.reviewId) : repositories;
}
