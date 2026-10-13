import type { Actor } from './actor';
import type { ApiToken } from './api-token';
import type { DateTime, Ulid, UsageLimit } from './common';

export const SIGN_IN_METHODS = ['github', 'local'] as const;
export type SignInMethod = (typeof SIGN_IN_METHODS)[number];

export type LimitUsage = {
    limit: UsageLimit;
    /** Null when the limit is off. Bytes for `repository_size` and `tenant_storage`. */
    max: number | null;
    /** For `requests_per_review` the largest open review, for `repository_size` the largest clone. */
    current: number;
};

export type TenantPlan = {
    name: string;
    /** Null without a free trial. */
    trialEndsAt: DateTime | null;
    /** A downgrade or a cancellation at the end of the billing cycle. A cancellation changes to the free plan. */
    pendingChange: { name: string; effectiveAt: DateTime } | null;
    /** The GitHub Marketplace listing. Null when the instance has none. */
    listingUrl: string | null;
};

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
    plan: TenantPlan;
    usage: LimitUsage[];
    links: {
        mcpUrl: string;
        docsUrl: string;
        skillUrl: string;
    };
};
