/**
 * Every link of the app into the help pages of docs/user. `DocsRenderingTest` checks that each one resolves to a page
 * and its fragment to a heading of that page.
 */
export const HELP = {
    center: '/docs',
    connectRepository: '/docs/getting-started#1-connect-a-repository',
    pickAPoint: '/docs/getting-started#2-pick-a-point-to-review',
    startAReview: '/docs/getting-started#3-start-a-review',
    connectAgentStep: '/docs/getting-started#4-connect-your-agent',
    keyboardShortcuts: '/docs/keyboard-shortcuts',
    connectYourAgent: '/docs/connect-your-agent',
    support: '/docs/support',
    privacy: '/docs/privacy',
    terms: '/docs/terms',
    apiTokens: '/docs/account#api-tokens',
    plan: '/docs/account#plan',
    usage: '/docs/account#usage-limits',
    repositoryData: '/docs/account#delete-repository-data',
    reviewTour: '/docs/account#review-tour',
    deleteAccount: '/docs/account#delete-your-account',
    checkEveryPullRequest: '/docs/pull-requests#check-every-pull-request',
    planFirst: '/docs/requests#ask-for-a-plan-first',
    comment: '/docs/comment',
} as const;

export type HelpUrl = (typeof HELP)[keyof typeof HELP];
