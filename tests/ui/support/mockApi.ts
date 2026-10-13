import type { Page, Request as BrowserRequest, Route } from '@playwright/test';
import type { ErrorCode } from '../../../assets/api/types/common';
import type { ErrorDetails } from '../../../assets/api/types/error';
import type { Me } from '../../../assets/api/types/me';
import { errorBody, me, mercureAuthorization } from './builders';
import { assertMatchesSchema, type SchemaRef } from './schemas';

export type MockResponse = { status: number; headers: Record<string, string>; body: string };

/** One request that the page sent. `params` holds the `:name` segments of the route that answered it. */
export type ApiCall = { method: string; path: string; query: URLSearchParams; headers: Record<string, string>; body: unknown; params: Record<string, string> };

/** Undefined passes the request to the route registered before this one. */
type Handler = (call: ApiCall) => MockResponse | undefined | Promise<MockResponse | undefined>;

type MockRoute = { method: string; pattern: RegExp; names: string[]; handler: Handler };

const WRITE_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

/** `SameOriginCsrfTokenManager`: 18 random bytes in base64url, and a cookie named after them. */
const CSRF_TOKEN = /^[A-Za-z0-9_-]{24}$/;

const LOGIN_PAGE = '<!DOCTYPE html><html lang="en"><title>Sign in</title><h1>Sign in to Slopmower</h1></html>';

export function json(ref: SchemaRef, body: unknown, status = 200): MockResponse {
    assertMatchesSchema(ref, body);

    return { status, headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) };
}

export function apiError(status: number, code: ErrorCode, message: string, details: ErrorDetails | null = null): MockResponse {
    return json('error.schema.json', errorBody(code, message, details), status);
}

/** A byte endpoint: V8 and R7. */
export function text(body: string): MockResponse {
    return { status: 200, headers: { 'content-type': 'text/plain; charset=utf-8' }, body };
}

export function noContent(status = 204): MockResponse {
    return { status, headers: {}, body: '' };
}

export function redirect(location: string): MockResponse {
    return { status: 302, headers: { location }, body: '' };
}

/**
 * The REST API of docs/specs and `POST /logout`, answered in the test process by the routes of one test. Every write
 * must carry the double-submit CSRF proof. A request without a route, a write without the proof, and a body that does
 * not validate are problems: the page gets a 500, and the test fails at the end.
 */
export class MockApi {
    /** The body of A1. */
    me: Me = me();
    readonly calls: ApiCall[] = [];
    readonly #routes: MockRoute[] = [];
    #problems: string[] = [];
    readonly #page: Page;

    constructor(page: Page) {
        this.#page = page;
    }

    async install(): Promise<void> {
        this.on('GET', '/api/v1/me', () => json('me.schema.json', this.me));
        this.on('POST', '/api/v1/mercure/authorize', () => json('lists.schema.json#/$defs/MercureAuthorization', mercureAuthorization()));
        this.#page.on('pageerror', (error) => this.#problems.push(`Uncaught in the page: ${error.message}`));
        await this.#page.route('**/api/v1/**', (route) => this.#dispatch(route));
        await this.#page.route('**/logout', (route) => this.#dispatch(route));
        await this.#page.route('**/login', (route) => route.fulfill({ contentType: 'text/html', body: LOGIN_PAGE }));
    }

    /**
     * `path` is a pathname with `:name` segments. A later route for the same request answers first.
     */
    on(method: string, path: string, handler: Handler): void {
        const { pattern, names } = compile(path);
        this.#routes.push({ method, pattern, names, handler });
    }

    /** The requests to the route, oldest first. */
    callsTo(method: string, path: string): ApiCall[] {
        const { pattern } = compile(path);

        return this.calls.filter((call) => call.method === method && pattern.test(call.path));
    }

    /** The JSON bodies of {@link callsTo}. */
    bodies(method: string, path: string): unknown[] {
        return this.callsTo(method, path).map((call) => call.body);
    }

    takeProblems(): string[] {
        const problems = this.#problems;
        this.#problems = [];

        return problems;
    }

    assertNoProblems(): void {
        if (this.#problems.length > 0) {
            throw new Error(`The mocked API saw problems:\n${this.#problems.join('\n')}`);
        }
    }

    async #dispatch(route: Route): Promise<void> {
        const request = route.request();
        const url = new URL(request.url());
        const call: ApiCall = { method: request.method(), path: url.pathname, query: url.searchParams, headers: await request.allHeaders(), body: requestBody(request), params: {} };
        this.calls.push(call);
        let response: MockResponse;

        try {
            if (WRITE_METHODS.has(call.method)) {
                assertCsrfProof(call);
            }
            response = await this.#respond(call);
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            this.#problems.push(`${call.method} ${call.path}${url.search}: ${message}`);
            response = { status: 500, headers: { 'content-type': 'application/json' }, body: JSON.stringify(errorBody('internal_error', message)) };
        }

        // The page can close before a slow route answers.
        await route.fulfill(response).catch(() => undefined);
    }

    async #respond(call: ApiCall): Promise<MockResponse> {
        for (const route of this.#routes.toReversed()) {
            const match = route.method === call.method ? route.pattern.exec(call.path) : null;
            if (null === match) {
                continue;
            }
            call.params = Object.fromEntries(route.names.map((name, index) => [name, decodeURIComponent(match[index + 1] ?? '')]));
            const response = await route.handler(call);
            if (undefined !== response) {
                return response;
            }
        }

        throw new Error('No mocked route answers it.');
    }
}

function compile(path: string): { pattern: RegExp; names: string[] } {
    const names: string[] = [];
    const source = path
        .split('/')
        .map((segment) => {
            if (segment.startsWith(':')) {
                names.push(segment.slice(1));

                return '([^/]+)';
            }

            return segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        })
        .join('/');

    return { pattern: new RegExp(`^${source}$`), names };
}

function requestBody(request: BrowserRequest): unknown {
    const data = request.postData();
    const contentType = request.headers()['content-type'] ?? '';

    if (null === data) {
        return null;
    }
    if (contentType.startsWith('application/json')) {
        return JSON.parse(data);
    }
    if (contentType.startsWith('application/x-www-form-urlencoded')) {
        return Object.fromEntries(new URLSearchParams(data));
    }

    return data;
}

/** The logout listener also reads the `_csrf_token` form field, which `check_header` compares with the header. */
function assertCsrfProof({ path, headers, body }: ApiCall): void {
    const token = headers['csrf-token'] ?? '';
    const cookies = (headers.cookie ?? '').split(/;\s*/);

    if (!CSRF_TOKEN.test(token) || !cookies.includes(`csrf-token_${token}=csrf-token`)) {
        throw new Error('The write has no double-submit CSRF proof.');
    }
    if ('/logout' === path && token !== (body as Record<string, unknown> | null)?._csrf_token) {
        throw new Error('The _csrf_token form field differs from the csrf-token header.');
    }
}
