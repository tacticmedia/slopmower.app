import type { ErrorCode } from './types/common';
import type { ErrorBody, ErrorDetails } from './types/error';

export type WriteMethod = 'POST' | 'PUT' | 'PATCH' | 'DELETE';

/**
 * The cookie name of `framework.csrf_protection`. It also names the double-submit header.
 */
const CSRF_COOKIE = 'csrf-token';

const CSRF_TOKEN_BYTES = 18;

export class ApiError extends Error {
    readonly status: number;
    readonly code: ErrorCode;
    readonly details: ErrorDetails | null;

    constructor(status: number, code: ErrorCode, message: string, details: ErrorDetails | null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
        this.details = details;
    }
}

export function apiGet<T>(path: string, signal?: AbortSignal): Promise<T> {
    return send<T>('GET', path, new Headers(), undefined, signal);
}

/**
 * Follows `nextCursor` to the last page. Returns the first page with the items of every page.
 */
export async function apiGetAllPages<P extends { items: unknown[]; nextCursor: string | null }>(path: string, signal?: AbortSignal): Promise<P> {
    const first = await apiGet<P>(path, signal);
    const items = [...first.items];

    for (let cursor = first.nextCursor; null !== cursor; ) {
        const page = await apiGet<P>(`${path}${path.includes('?') ? '&' : '?'}cursor=${encodeURIComponent(cursor)}`, signal);
        items.push(...page.items);
        cursor = page.nextCursor;
    }

    return { ...first, items, nextCursor: null };
}

/**
 * Resolves to undefined for a response without a JSON body, such as 202 or 204.
 */
export function apiWrite<T = undefined>(method: WriteMethod, path: string, body?: unknown, signal?: AbortSignal): Promise<T> {
    const headers = new Headers({ [CSRF_COOKIE]: doubleSubmitCsrfToken() });
    if (undefined !== body) {
        headers.set('Content-Type', 'application/json');
    }

    return send<T>(method, path, headers, undefined === body ? undefined : JSON.stringify(body), signal);
}

/**
 * The logout listener reads the token from the `_csrf_token` form field and answers with a redirect.
 */
export async function signOut(): Promise<void> {
    const token = doubleSubmitCsrfToken();
    const response = await fetch('/logout', {
        method: 'POST',
        headers: { [CSRF_COOKIE]: token },
        body: new URLSearchParams({ _csrf_token: token }),
        credentials: 'same-origin',
        redirect: 'manual',
    });

    if ('opaqueredirect' !== response.type) {
        throw await toApiError(response);
    }
}

async function send<T>(method: 'GET' | WriteMethod, path: string, headers: Headers, body: string | undefined, signal: AbortSignal | undefined): Promise<T> {
    headers.set('Accept', 'application/json');
    const response = await fetch(path, { method, headers, body, signal, credentials: 'same-origin' });

    if (!response.ok) {
        throw await toApiError(response);
    }

    return isJson(response) ? ((await response.json()) as T) : (undefined as T);
}

async function toApiError(response: Response): Promise<ApiError> {
    const body: unknown = isJson(response) ? await response.json().catch(() => null) : null;

    if (isErrorBody(body)) {
        return new ApiError(response.status, body.error.code, body.error.message, body.error.details);
    }

    return new ApiError(response.status, 'internal_error', `The server answered HTTP ${response.status} without an error body.`, null);
}

function isJson(response: Response): boolean {
    return response.headers.get('Content-Type')?.startsWith('application/json') ?? false;
}

function isErrorBody(body: unknown): body is ErrorBody {
    if ('object' !== typeof body || null === body || !('error' in body)) {
        return false;
    }
    const { error } = body;

    return 'object' === typeof error && null !== error && 'code' in error && 'string' === typeof error.code && 'message' in error && 'string' === typeof error.message;
}

/**
 * The double-submit proof of Symfony's `SameOriginCsrfTokenManager`: a random token in the header, plus a cookie
 * named after it. The server clears the cookie in its response.
 */
function doubleSubmitCsrfToken(): string {
    const bytes = crypto.getRandomValues(new Uint8Array(CSRF_TOKEN_BYTES));
    const token = btoa(String.fromCharCode(...bytes)).replaceAll('+', '-').replaceAll('/', '_');
    const secure = 'https:' === location.protocol;
    document.cookie = `${secure ? '__Host-' : ''}${CSRF_COOKIE}_${token}=${CSRF_COOKIE}; path=/; samesite=strict${secure ? '; secure' : ''}`;

    return token;
}
