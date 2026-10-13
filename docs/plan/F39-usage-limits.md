# F39 Usage limits

Workflow, conventions, and status: [feature track](features.md). Jan selected a GitHub Marketplace listing on 2026-10-09. A listed App is public, so every GitHub user can create a tenant and clone repositories to our disk (risk "Public App" in [architecture section 12](../architecture.md#12-risks)). The limits are also what a Marketplace plan sells (F42, D65).

## Goal

- Per-tenant limits and API and MCP rate limits, as the backlog item [usage-limits.md](../backlog/usage-limits.md) specifies.
- Each per-tenant limit value comes from the plan of the tenant.

## Decisions

- **Specification.** [usage-limits.md](../backlog/usage-limits.md) as written: the per-tenant limits with `limit_exceeded`, the `symfony/rate-limiter` limiters with `rate_limited`, and both error codes in `ErrorCode`, `error.schema.json`, and the skill.
- **Change to the specification: plan values.** Each per-tenant limit value comes from the plan of the tenant (F42). Until F42 exists, every tenant has the free plan. F39 names the parameters as the values of the free plan, so F42 adds the paid plans next to them without a rename. A value of zero or an empty value switches the limit off, as the specification says.
- **Rate limits do not depend on the plan.** A named limiter of `framework.rate_limiter` has one static configuration. The rate limits protect the instance, so their values are instance parameters only.
- **Token buckets only.** Review on 2026-10-13: a `sliding_window` limiter reports a `Retry-After` shorter than the real wait, because `SlidingWindow` spreads the window over its hits. An agent that waits `retryAfterSeconds` is then refused again. So `api_user` and `review_start` are `token_bucket` limiters too, with the same limits per interval.
- **Carry-over.** V15 opens requests, so it checks the requests per review too.
- **Clone size.** `Repository` stores the measured `size-pack`, so the tenant total is a query and not a walk of the disk.
- **Settings data.** Specs first: `Me` gets `usage`, a list of `{limit, max, current}`, the shape of the `details` of `limit_exceeded`. `max` is null for a limit that is off.
- D32 in [architecture section 11](../architecture.md#11-decisions) changes from "None in the proof of concept" to F39.

## Depends on

Nothing new. M09, M11, M14, M15, M16, M19, and M33 are done.

## In scope

- `composer require symfony/rate-limiter`. The limiters `api_token`, `api_user`, `sync_fetch`, and `review_start` with a cache pool in PostgreSQL and the advisory-lock `LockFactory`, so every `php` and `worker` process shares the state.
- The `kernel.request` listener for `^/api/v1` and `^/_mcp`, the check per `tools/call` in the MCP tool runner, and the response headers of the specification.
- The per-tenant checks: connect repository (R3), start review (V1), open request and submit drafts, and the clone size before the clone (GitHub `size`), after the clone, and after each fetch.
- Specs: `error.schema.json`, specs README section 4, `me.schema.json` `usage`, the fixtures, and the REST and MCP pages that list errors per operation.
- Skill: the two rows of the specification.
- UI: the use and limit of each item in Settings.
- Configuration: the parameters and their variables in `.env`, `.env.prod.dist`, and `docs/deployment.md`.
- Docs: D32, [requirements section 4](../requirements.md#4-non-goals-of-the-proof-of-concept), the backlog item status, and `docs/user/` where it names errors.

## Out of scope

- Plans and the plan of a tenant (F42).
- A sign-in allowlist (candidate "Sign-in allowlist").
- Limits per user inside a tenant.

## Acceptance tests

The acceptance criteria 1 to 8 of [usage-limits.md](../backlog/usage-limits.md#acceptance-criteria), and:

- **F** `UsageTest`: `Me.usage` lists each limit with its current use, and a limit set to zero has `max` null.
- **UI** `SettingsUsage.spec.ts`: Settings shows the use and the limit of each item.

## Verification

- `make check` is green, with an empty `doctrine:migrations:diff`.
- Manual check: with the limit of open reviews set to 1, a second review start gives the `limit_exceeded` message in the UI, and an agent that loops on `list_requests` gets `rate_limited` and waits.
