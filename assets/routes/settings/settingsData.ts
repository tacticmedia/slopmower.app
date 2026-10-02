import type { ActionFunctionArgs, LoaderFunctionArgs } from 'react-router';
import { ApiError, apiGet, apiWrite } from '../../api/client';
import type { ApiToken, ApiTokenWithSecret } from '../../api/types/api-token';
import type { Ulid } from '../../api/types/common';
import type { InvalidInputDetails } from '../../api/types/error';
import type { CreateTokenInput } from '../../api/types/inputs';
import type { RepositoryPage, TokenList } from '../../api/types/lists';
import type { Repository } from '../../api/types/repository';

const REPOSITORY_PAGE_LIMIT = 500;

export type SettingsData = {
    tokens: ApiToken[];
    repositories: Repository[];
};

export type SettingsIntent = { intent: 'create'; input: Required<CreateTokenInput> } | { intent: 'revoke'; tokenId: Ulid };

export type CreateTokenFailure = {
    message: string;
    violations: InvalidInputDetails['violations'];
};

export type CreateTokenResult = { token: ApiTokenWithSecret; failure: null } | { token: null; failure: CreateTokenFailure };

export async function settingsLoader({ request }: LoaderFunctionArgs): Promise<SettingsData> {
    const [tokens, repositories] = await Promise.all([apiGet<TokenList>('/api/v1/tokens', request.signal), allRepositories(request.signal)]);

    return { tokens: tokens.items, repositories };
}

/**
 * Answers a create with the secret or with the input failure, for the dialog. Other errors go to the error page.
 */
export async function settingsAction({ request }: ActionFunctionArgs): Promise<CreateTokenResult | null> {
    const submitted = (await request.json()) as SettingsIntent;

    if ('revoke' === submitted.intent) {
        await apiWrite('DELETE', `/api/v1/tokens/${encodeURIComponent(submitted.tokenId)}`, undefined, request.signal);

        return null;
    }

    try {
        return { token: await apiWrite<ApiTokenWithSecret>('POST', '/api/v1/tokens', submitted.input, request.signal), failure: null };
    } catch (error) {
        if (error instanceof ApiError && ('invalid_input' === error.code || 'not_found' === error.code)) {
            const violations = null !== error.details && 'violations' in error.details ? error.details.violations : [];

            return { token: null, failure: { message: error.message, violations } };
        }

        throw error;
    }
}

async function allRepositories(signal: AbortSignal): Promise<Repository[]> {
    const repositories: Repository[] = [];
    let cursor: string | null = null;

    do {
        const query = new URLSearchParams({ limit: String(REPOSITORY_PAGE_LIMIT) });
        if (null !== cursor) {
            query.set('cursor', cursor);
        }
        const page: RepositoryPage = await apiGet<RepositoryPage>(`/api/v1/repositories?${query.toString()}`, signal);
        repositories.push(...page.items);
        cursor = page.nextCursor;
    } while (null !== cursor);

    return repositories;
}
