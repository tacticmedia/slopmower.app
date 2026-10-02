import type { Actor } from './actor';
import type { ApiToken } from './api-token';
import type { Ulid } from './common';

export const SIGN_IN_METHODS = ['github', 'local'] as const;
export type SignInMethod = (typeof SIGN_IN_METHODS)[number];

export type Me = {
    user: {
        id: Ulid;
        login: string;
        name: string | null;
        avatarUrl: string | null;
        signInMethod: SignInMethod;
    };
    tenantId: Ulid;
    caller: Actor;
    token: ApiToken | null;
    features: {
        localRepositories: boolean;
        githubApp: { slug: string; installUrl: string } | null;
    };
    links: {
        mcpUrl: string;
        docsUrl: string;
        skillUrl: string;
    };
};
