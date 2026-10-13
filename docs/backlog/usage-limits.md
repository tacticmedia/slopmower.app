# Usage limits and rate limiting

Status: done in [F39](../plan/F39-usage-limits.md), which takes each per-tenant limit value from the plan of the tenant (F42). The specification already bounds some lists: 500 drafts per user and review, 50 active tokens per user, and 1,000 repository candidates.

## Why

- A hosted instance serves many tenants from one disk, one database, and one worker pool. One tenant can fill the disk with large clones, or load the worker with index and re-anchoring jobs.
- Agents poll. A wrong loop in an agent can send thousands of calls per minute with one token.
- Synchronous fetches at review start, `mark_ready_for_review`, and `get_request` with `at` are expensive. Repeated calls can block the fetch lock of a repository.

## What

### Per-tenant limits

Each limit is an instance parameter with an environment variable, so that a self-hosted instance can change or switch off a limit. The defaults are a proposal.

| Limit | Default | Checked at | Result when exceeded |
|---|---|---|---|
| Connected repositories per tenant | 25 | Connect repository | 409 `limit_exceeded` |
| Open reviews per tenant | 20 | Start review | 409 `limit_exceeded` |
| Requests per review | 1,000 | Open request, submit drafts, carry over | 409 `limit_exceeded` |
| Size of one server clone | 2 GiB, measured as `size-pack` from `git count-objects -v` | After the first clone and after each fetch. For a GitHub source also before the clone, from the `size` field of the repository in the GitHub API. | The clone job fails: `sync.state` is `failed` and `sync.lastError` names the limit. A fetch that passes the limit keeps the clone, sets `lastError`, and refuses further fetches, so operations that need a fetch get `repository_unavailable` with reason `fetch_failed`. |
| Total size of the tenant's clones | 10 GiB | As the clone size | As the clone size |

Settings shows the use and the limit of each item.

### Rate limiting

`symfony/rate-limiter` with named limiters under `framework.rate_limiter`. A `kernel.request` listener runs after the firewall for `^/api/v1` and `^/_mcp`. It consumes one token from each limiter that applies, with `RateLimiterFactoryInterface::create($key)->consume(1)`, and refuses the call when `isAccepted()` is false.

| Limiter | Policy | Key | Default |
|---|---|---|---|
| `api_token` | `token_bucket` | API token ID | `limit: 300`, `rate: {interval: '1 minute', amount: 300}` |
| `api_user` | `token_bucket` | User ID, for the session and all tokens of the user together | `limit: 1200`, `rate: {interval: '1 minute', amount: 1200}` |
| `sync_fetch` | `token_bucket` | Repository ID | `limit: 10`, `rate: {interval: '1 minute', amount: 10}`, for the synchronous fetch triggers |
| `review_start` | `token_bucket` | Tenant ID | `limit: 30`, `rate: {interval: '1 hour', amount: 30}` |

- The limiter state must be shared by every `php` process and container. It uses a PostgreSQL-backed cache pool and the advisory-lock `LockFactory`.
- REST: a refused call gets HTTP 429 with a `Retry-After` header in seconds, `X-RateLimit-Limit`, and `X-RateLimit-Remaining`. The values come from `getRetryAfter()`, `getLimit()`, and `getRemainingTokens()` of the `RateLimit` object.
- MCP: the tool runner checks the limiters for each `tools/call` and returns an `isError` result with `rate_limited`, so that the agent can wait and retry. The listener on `^/_mcp` keeps a higher limit that answers HTTP 429, as a last line of defence.
- The GitHub webhook endpoint is not rate limited. Its signature check protects it.

### Error codes

Both codes are new error codes for new situations, which the v1 contract allows. They are added to `ErrorCode` and to `error.schema.json`.

| Code | HTTP | `details` |
|---|---|---|
| `rate_limited` | 429 | `{retryAfterSeconds, limit}` |
| `limit_exceeded` | 409 | `{limit, max, current}`. `limit` is one of `repositories`, `open_reviews`, `requests_per_review`, `repository_size`, `tenant_storage`. |

The skill gets two rows: on `rate_limited`, wait `retryAfterSeconds`, then retry; on `limit_exceeded`, stop and tell the user.

## Acceptance criteria

1. Each per-tenant limit gives `limit_exceeded` with the specified `details` one call after the limit, through REST and MCP where the operation exists. A test sets a low limit through its parameter.
2. A limit set to zero or to an empty value is switched off.
3. The clone size check fails the clone job of a repository above the limit and deletes the partial clone.
4. `RateLimitTest` uses the mock clock: the call after the limit gets 429 with a correct `Retry-After`, and a call after the interval succeeds.
5. Two tokens of one user have separate `api_token` buckets and share the `api_user` window.
6. An MCP tool call over the limit returns `isError` with `rate_limited`, validated against `error.schema.json`.
7. The limiter state is shared: a test consumes tokens through two kernels and sees one count.
8. The specifications, the skill, and the user documentation list both error codes.

## Dependencies

- M11 REST foundation, M14 API tokens, M15 and M16 MCP tools: the listener and the tool runner.
- M09 local source and M33 GitHub App repositories: the clone size check.
- M19 Settings: the display of use and limits.
