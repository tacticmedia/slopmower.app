import { ApiError, apiGet, apiWrite } from '../api/client';
import type { CodeToken } from '../api/types/lists';
import type { Review } from '../api/types/review';

/** R11 asks again this long before the token expires. The server drops its kept token 5 minutes before. */
const RENEW_BEFORE_MS = 4 * 60_000;

/** R11 is asked at most this often, except after a 401. */
const MIN_ASK_INTERVAL_MS = 60_000;

const BINARY_CHECK_BYTES = 8000;

/** A blob SHA never changes, so its bytes are kept, within these bounds. */
const MAX_CACHED_BLOBS = 64;
const MAX_CACHED_BYTES = 16 * 1024 * 1024;

/** An `img` shows the bytes of a `blob:` URL only with an image type. */
const IMAGE_TYPES: Readonly<Record<string, string>> = { svg: 'image/svg+xml', png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', webp: 'image/webp' };

/** The tokens of R11, in memory only (D114). */
const tokens = new Map<string, { token: Promise<CodeToken>; askedAtMs: number }>();

/** The repository of a review never changes. */
const reviewRepositories = new Map<string, Promise<string>>();

/** In memory only, oldest first. */
const blobs = new Map<string, Uint8Array>();
let cachedBytes = 0;

/** Is the 409 of V8 and R7 for a Browser only repository. */
export function isCodeInBrowser(error: unknown): boolean {
    return error instanceof ApiError && 'repository_unavailable' === error.code && null !== error.details && 'reason' in error.details && 'code_in_browser' === error.details.reason;
}

/** The repository ID of a review, read once with V3. */
export function reviewRepositoryId(reviewId: string): Promise<string> {
    const kept = reviewRepositories.get(reviewId);
    if (undefined !== kept) {
        return kept;
    }
    const read = apiGet<Review>(`/api/v1/reviews/${encodeURIComponent(reviewId)}`).then((review) => review.repositoryId);
    reviewRepositories.set(reviewId, read);
    read.catch(() => reviewRepositories.delete(reviewId));

    return read;
}

/** The bytes of a blob, from `api.github.com`. */
export async function gitHubBlob(repositoryId: string, blobSha: string, signal?: AbortSignal): Promise<Uint8Array> {
    const cached = blobs.get(blobSha);
    if (undefined !== cached) {
        blobs.delete(blobSha);
        blobs.set(blobSha, cached);

        return cached;
    }
    const bytes = new Uint8Array(await (await gitHubRead(repositoryId, `git/blobs/${encodeURIComponent(blobSha)}`, signal)).arrayBuffer());
    keep(blobSha, bytes);

    return bytes;
}

/** A README image at a commit, which has a path and no blob SHA, typed by its extension. */
export async function gitHubImage(repositoryId: string, path: string, commitSha: string, signal?: AbortSignal): Promise<Blob> {
    const response = await gitHubRead(repositoryId, `contents/${path.split('/').map(encodeURIComponent).join('/')}?ref=${encodeURIComponent(commitSha)}`, signal);

    return new Blob([await response.arrayBuffer()], { type: IMAGE_TYPES[path.slice(path.lastIndexOf('.') + 1).toLowerCase()] ?? '' });
}

/** The rule of the server: a NUL byte in the first 8,000 bytes. */
export function isBinary(bytes: Uint8Array): boolean {
    return bytes.subarray(0, BINARY_CHECK_BYTES).includes(0);
}

/** The rule of the server: lines end at LF, and a final line without LF counts. */
export function lineCount(text: string): number {
    return '' === text ? 0 : text.split('\n').length - (text.endsWith('\n') ? 1 : 0);
}

/** Drops the oldest blobs above the bounds. */
function keep(blobSha: string, bytes: Uint8Array): void {
    if (blobs.has(blobSha) || bytes.byteLength > MAX_CACHED_BYTES) {
        return;
    }
    blobs.set(blobSha, bytes);
    cachedBytes += bytes.byteLength;

    for (const [oldestSha, oldest] of blobs) {
        if (blobs.size <= MAX_CACHED_BLOBS && cachedBytes <= MAX_CACHED_BYTES) {
            break;
        }
        blobs.delete(oldestSha);
        cachedBytes -= oldest.byteLength;
    }
}

/** A 401 drops the token and asks once more, because GitHub can revoke a token before it expires. */
async function gitHubRead(repositoryId: string, path: string, signal: AbortSignal | undefined, retried = false): Promise<Response> {
    const { token, owner, name } = await codeToken(repositoryId);
    const response = await fetch(`https://api.github.com/repos/${encodeURIComponent(owner)}/${encodeURIComponent(name)}/${path}`, {
        signal,
        headers: { Accept: 'application/vnd.github.raw+json', Authorization: `Bearer ${token}` },
    });

    if (401 === response.status && !retried) {
        tokens.delete(repositoryId);

        return gitHubRead(repositoryId, path, signal, true);
    }

    if (!response.ok) {
        throw gitHubError(response);
    }

    return response;
}

/** Concurrent reads share one R11 call. */
async function codeToken(repositoryId: string): Promise<CodeToken> {
    const kept = tokens.get(repositoryId);

    if (undefined !== kept) {
        const token = await kept.token.catch(() => null);
        const fresh = null !== token && Date.parse(token.expiresAt) - RENEW_BEFORE_MS > Date.now();

        if (null !== token && (fresh || Date.now() - kept.askedAtMs < MIN_ASK_INTERVAL_MS)) {
            return token;
        }

        if (tokens.get(repositoryId) !== kept) {
            return codeToken(repositoryId);
        }
    }

    const asked = apiWrite<CodeToken>('POST', `/api/v1/repositories/${encodeURIComponent(repositoryId)}/code-token`);
    tokens.set(repositoryId, { token: asked, askedAtMs: Date.now() });
    asked.catch(() => {
        if (tokens.get(repositoryId)?.token === asked) {
            tokens.delete(repositoryId);
        }
    });

    return asked;
}

/** A refusal for a rate limit becomes the `rate_limited` error of the server, so the screens show it alike. */
function gitHubError(response: Response): ApiError {
    const retryAfter = response.headers.get('retry-after');

    if (429 === response.status || (403 === response.status && ('0' === response.headers.get('x-ratelimit-remaining') || null !== retryAfter))) {
        const resetUnixSeconds = Number(response.headers.get('x-ratelimit-reset'));
        const retryAfterSeconds = Math.max(1, Number(retryAfter) || Math.ceil(resetUnixSeconds - Date.now() / 1000) || 60);

        return new ApiError(429, 'rate_limited', `GitHub refused the read for its rate limit. Wait ${retryAfterSeconds} seconds, then try again.`, {
            retryAfterSeconds,
            limit: Number(response.headers.get('x-ratelimit-limit')) || 0,
        });
    }

    return 404 === response.status
        ? new ApiError(404, 'not_found', 'GitHub has no such file.', null)
        : new ApiError(502, 'internal_error', `GitHub answered HTTP ${response.status}.`, null);
}
