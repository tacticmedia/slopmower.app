import { afterEach, beforeEach, describe, expect, it, type Mock, vi } from 'vitest';
import invalidInput from '../../docs/specs/fixtures/error.invalid-input.json';
import meFixture from '../../docs/specs/fixtures/me.local-user.json';
import { ApiError, apiGet, apiGetAllPages, apiWrite } from './client';

type Fetch = (path: string, init: RequestInit) => Promise<Response>;
type FetchCall = Parameters<Fetch>;

function jsonResponse(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json; charset=utf-8' } });
}

describe('ApiClientTest', () => {
    let fetchMock: Mock<Fetch>;
    const fakeDocument = { cookie: '' };

    beforeEach(() => {
        fetchMock = vi.fn<Fetch>();
        fakeDocument.cookie = '';
        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('document', fakeDocument);
        vi.stubGlobal('location', { protocol: 'http:' });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('Maps an Error body to ApiError with code and details', async () => {
        fetchMock.mockResolvedValue(jsonResponse(422, invalidInput));

        const failure = apiWrite('POST', '/api/v1/requests/01K6AZZ9REQ000000000000001/wont-do', { reason: 'too short' });

        await expect(failure).rejects.toBeInstanceOf(ApiError);
        await expect(failure).rejects.toMatchObject({
            status: 422,
            code: 'invalid_input',
            message: invalidInput.error.message,
            details: invalidInput.error.details,
        });
    });

    it('Sends the CSRF proof only on writes', async () => {
        fetchMock.mockImplementation(async () => jsonResponse(200, meFixture));

        await apiGet('/api/v1/me');
        const [, readInit] = fetchMock.mock.calls[0] as FetchCall;
        expect(new Headers(readInit.headers).has('csrf-token')).toBe(false);
        expect(fakeDocument.cookie).toBe('');

        await apiWrite('DELETE', '/api/v1/tokens/01K6AZZ9TOKEN0000000000001');
        const [, writeInit] = fetchMock.mock.calls[1] as FetchCall;
        const token = new Headers(writeInit.headers).get('csrf-token') ?? '';
        expect(token).toMatch(/^[-_A-Za-z0-9]{24,}$/);
        expect(fakeDocument.cookie).toBe(`csrf-token_${token}=csrf-token; path=/; samesite=strict`);
    });

    it('Follows nextCursor and returns the items of every page', async () => {
        fetchMock
            .mockResolvedValueOnce(jsonResponse(200, { items: [1, 2], nextCursor: 'c+1' }))
            .mockResolvedValueOnce(jsonResponse(200, { items: [3], nextCursor: null }));

        const page = await apiGetAllPages<{ items: number[]; nextCursor: string | null }>('/api/v1/repositories?limit=500');

        expect(page).toEqual({ items: [1, 2, 3], nextCursor: null });
        expect(fetchMock.mock.calls.map(([path]) => path)).toEqual(['/api/v1/repositories?limit=500', '/api/v1/repositories?limit=500&cursor=c%2B1']);
    });
});
