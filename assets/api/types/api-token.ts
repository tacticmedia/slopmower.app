import type { DateTime, Ulid } from './common';

export const API_TOKEN_SCOPES = ['all_repositories', 'repository'] as const;
export type ApiTokenScope = (typeof API_TOKEN_SCOPES)[number];

export const API_TOKEN_STATES = ['active', 'expired', 'revoked'] as const;
export type ApiTokenState = (typeof API_TOKEN_STATES)[number];

/** The agent name on every event made with the token. */
export type TokenName = string;

type ScopeFields =
    | { scope: 'all_repositories'; repositoryId: null; repositoryName: null }
    | { scope: 'repository'; repositoryId: Ulid; repositoryName: string };

export type ApiToken = {
    id: Ulid;
    name: TokenName;
    /** First 9 characters of the secret. */
    prefix: string;
    state: ApiTokenState;
    createdAt: DateTime;
    expiresAt: DateTime | null;
    /** Updated at most once per minute. */
    lastUsedAt: DateTime | null;
    revokedAt: DateTime | null;
} & ScopeFields;

export type ApiTokenWithSecret = ApiToken & {
    /** Shown once. */
    secret: string;
};
