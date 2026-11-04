import type { Page, Route } from '@playwright/test';
import { codeToken } from './builders';

/** The headers of `api.github.com` that a cross-origin read needs. */
const CORS = {
    'access-control-allow-origin': '*',
    'access-control-allow-headers': 'Authorization, Accept',
    'access-control-expose-headers': 'ETag, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After',
};

/**
 * `GET https://api.github.com/repos/{owner}/{name}/git/blobs/{sha}` of Browser only (D114), answered from `blobs`. It
 * keeps each call with its `Authorization`, refuses the next call with 401 after {@link revokeToken()}, and from
 * {@link rateLimit()} on refuses as GitHub does.
 */
export class FakeGitHub {
    /** The token that R11 of the fakes gives. */
    static readonly TOKEN = codeToken().token;

    readonly calls: { path: string; authorization: string | null }[] = [];
    readonly #page: Page;
    readonly #blobs: (blobSha: string) => string | undefined;
    #rateLimited = false;
    #revoked = false;

    constructor(page: Page, blobs: (blobSha: string) => string | undefined) {
        this.#page = page;
        this.#blobs = blobs;
    }

    async install(): Promise<void> {
        await this.#page.route('https://api.github.com/**', (route) => this.#answer(route));
    }

    rateLimit(): void {
        this.#rateLimited = true;
    }

    revokeToken(): void {
        this.#revoked = true;
    }

    async #answer(route: Route): Promise<void> {
        const request = route.request();
        if ('OPTIONS' === request.method()) {
            return route.fulfill({ status: 204, headers: CORS });
        }
        const path = new URL(request.url()).pathname;
        this.calls.push({ path, authorization: (await request.headerValue('authorization')) ?? null });
        if (this.#revoked) {
            this.#revoked = false;

            return route.fulfill({ status: 401, headers: CORS, json: { message: 'Bad credentials' } });
        }
        if (this.#rateLimited) {
            const reset = Math.ceil(Date.now() / 1000) + 120;

            return route.fulfill({ status: 403, headers: { ...CORS, 'x-ratelimit-limit': '5000', 'x-ratelimit-remaining': '0', 'x-ratelimit-reset': String(reset) }, json: { message: 'API rate limit exceeded' } });
        }
        const blobSha = /\/git\/blobs\/([0-9a-f]+)$/.exec(path)?.[1];
        const body = undefined === blobSha ? undefined : this.#blobs(blobSha);

        return undefined === body ? route.fulfill({ status: 404, headers: CORS, json: { message: 'Not Found' } }) : route.fulfill({ status: 200, headers: { ...CORS, 'content-type': 'application/vnd.github.raw' }, body });
    }
}
