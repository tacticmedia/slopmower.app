# Slopmower REST API v1

This file has the endpoint index and the account, repository, review, and code endpoints. [rest-api-requests.md](rest-api-requests.md) has the requests, updates, drafts, API tokens, and the routes outside `/api/v1`. The MCP tools in [mcp.md](mcp.md) return the same JSON.

## 1. Conventions

[README.md](README.md) defines the shared rules:

- transport, formats, and text limits: [section 2](README.md#2-conventions);
- authentication modes `S` and `B`, actors, CSRF, and visibility: [section 3](README.md#3-authentication-actors-and-scope);
- error codes, HTTP statuses, and the check order: [section 4](README.md#4-errors);
- pagination: [section 5](README.md#5-pagination);
- caching and ETags: [section 6](README.md#6-caching-and-etags);
- repository sources, names, pin refs, and commit availability: [section 7](README.md#7-repository-sources-names-and-commit-availability);
- versioning: [section 8](README.md#8-versioning-and-compatibility).

Request bodies are defined in [inputs.schema.json](schemas/inputs.schema.json). Each endpoint below names its body definition.

## 2. Endpoint index

| # | Method | Path | Authentication | Success | Response schema |
|---|---|---|---|---|---|
| [A1](#a1-get-the-caller) | GET | `/api/v1/me` | S, B | 200 | [`me.schema.json`](schemas/me.schema.json) |
| [A2](#a2-delete-the-account) | DELETE | `/api/v1/account?confirm={login}` | S | 202 | none |
| [A3](#a3-authorize-live-updates) | POST | `/api/v1/mercure/authorize` | S | 200 | [`lists.schema.json#/$defs/MercureAuthorization`](schemas/lists.schema.json) |
| [A4](#a4-update-the-preferences) | PATCH | `/api/v1/me/preferences` | S | 200 | [`me.schema.json#/$defs/Preferences`](schemas/me.schema.json) |
| [R1](#r1-list-repositories) | GET | `/api/v1/repositories` | S, B | 200 | [`lists.schema.json#/$defs/RepositoryPage`](schemas/lists.schema.json) |
| [R2](#r2-list-repository-candidates) | GET | `/api/v1/repositories/candidates` | S | 200 | [`lists.schema.json#/$defs/CandidateList`](schemas/lists.schema.json) |
| [R3](#r3-connect-a-repository) | POST | `/api/v1/repositories` | S | 201 or 200 | [`repository.schema.json`](schemas/repository.schema.json) |
| [R4](#r4-get-a-repository) | GET | `/api/v1/repositories/{repositoryId}` | S, B | 200 | [`repository.schema.json`](schemas/repository.schema.json) |
| [R5](#r5-fetch-a-repository) | POST | `/api/v1/repositories/{repositoryId}/fetch` | S, B | 202 | [`repository.schema.json`](schemas/repository.schema.json) |
| [R6](#r6-delete-repository-data) | DELETE | `/api/v1/repositories/{repositoryId}?confirm={name}` | S | 202 | none |
| [R7](#r7-get-a-blob) | GET | `/api/v1/repositories/{repositoryId}/blobs/{blobSha}` | S, B | 200 or 304 | bytes |
| [R8](#r8-check-every-pull-request) | POST | `/api/v1/repositories/{repositoryId}/check-every-pull-request` | S | 200 | [`repository.schema.json`](schemas/repository.schema.json) |
| [R9](#r9-list-the-latest-commits) | GET | `/api/v1/repositories/{repositoryId}/commits` | S, B | 200 | [`lists.schema.json#/$defs/CommitLog`](schemas/lists.schema.json) |
| [V1](#v1-start-a-review) | POST | `/api/v1/repositories/{repositoryId}/reviews` | S, B | 201 | [`review.schema.json`](schemas/review.schema.json) |
| [V2](#v2-list-reviews) | GET | `/api/v1/repositories/{repositoryId}/reviews` | S, B | 200 | [`lists.schema.json#/$defs/ReviewPage`](schemas/lists.schema.json) |
| [V3](#v3-get-a-review) | GET | `/api/v1/reviews/{reviewId}` | S, B | 200 | [`review.schema.json`](schemas/review.schema.json) |
| [V4](#v4-close-a-review) | POST | `/api/v1/reviews/{reviewId}/close` | S | 200 | [`review.schema.json`](schemas/review.schema.json) |
| [V5](#v5-get-the-index-status) | GET | `/api/v1/reviews/{reviewId}/index` | S, B | 200 | [`review.schema.json#/$defs/IndexStatus`](schemas/review.schema.json) |
| [V6](#v6-list-a-tree) | GET | `/api/v1/reviews/{reviewId}/tree` | S, B | 200 | [`lists.schema.json#/$defs/TreeListing`](schemas/lists.schema.json) |
| [V7](#v7-get-file-metadata) | GET | `/api/v1/reviews/{reviewId}/file?path=` | S, B | 200 or 304 | [`file-content.schema.json`](schemas/file-content.schema.json) |
| [V8](#v8-get-raw-file-content) | GET | `/api/v1/reviews/{reviewId}/raw?path=` | S, B | 200 or 304 | bytes |
| [V9](#v9-list-symbols) | GET | `/api/v1/reviews/{reviewId}/symbols?path=` | S, B | 200 or 304 | [`lists.schema.json#/$defs/SymbolList`](schemas/lists.schema.json) |
| [V10](#v10-get-updates) | GET | `/api/v1/reviews/{reviewId}/updates` | S, B | 200 | [`updates-page.schema.json`](schemas/updates-page.schema.json) |
| [V11](#v11-list-viewed-files) | GET | `/api/v1/reviews/{reviewId}/viewed-files` | S | 200 | [`lists.schema.json#/$defs/ViewedFiles`](schemas/lists.schema.json) |
| [V12](#v12-mark-a-file-as-viewed) | PUT | `/api/v1/reviews/{reviewId}/viewed-files` | S | 200 | [`lists.schema.json#/$defs/ViewedFiles`](schemas/lists.schema.json) |
| [V13](#v13-delete-a-review) | DELETE | `/api/v1/reviews/{reviewId}` | S | 204 | none |
| [V14](#v14-find-declarations) | GET | `/api/v1/reviews/{reviewId}/declarations?path=` | S, B | 200 | [`lists.schema.json#/$defs/DeclarationList`](schemas/lists.schema.json) |
| [V15](#v15-carry-over-open-requests) | POST | `/api/v1/reviews/{reviewId}/carry-over` | S | 200 | [`carry-over-result.schema.json`](schemas/carry-over-result.schema.json) |
| [V16](#v16-search-files-and-symbols) | GET | `/api/v1/reviews/{reviewId}/search?q=` | S, B | 200 | [`lists.schema.json#/$defs/SearchHitList`](schemas/lists.schema.json) |
| [V17](#v17-get-the-blame-of-a-file) | GET | `/api/v1/reviews/{reviewId}/blame?path=` | S, B | 200 or 304 | [`blame.schema.json`](schemas/blame.schema.json) |
| [Q1](rest-api-requests.md#q1-list-requests) | GET | `/api/v1/reviews/{reviewId}/requests` | S, B | 200 | [`lists.schema.json#/$defs/RequestPage`](schemas/lists.schema.json) |
| [Q2](rest-api-requests.md#q2-open-a-request) | POST | `/api/v1/reviews/{reviewId}/requests` | S | 201 | [`request.schema.json`](schemas/request.schema.json) |
| [Q3](rest-api-requests.md#q3-get-a-request) | GET | `/api/v1/requests/{requestId}` | S, B | 200 | [`request.schema.json`](schemas/request.schema.json) |
| [Q4](rest-api-requests.md#q4-comment-on-a-request) | POST | `/api/v1/requests/{requestId}/comments` | S, B | 201 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [Q5](rest-api-requests.md#q5-claim-a-request) | POST | `/api/v1/requests/{requestId}/claim` | B | 200 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [Q6](rest-api-requests.md#q6-release-a-request) | POST | `/api/v1/requests/{requestId}/release` | S, B | 200 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [Q7](rest-api-requests.md#q7-link-a-commit-to-requests) | POST | `/api/v1/commit-links` | B | 200 | [`link-commits-result.schema.json`](schemas/link-commits-result.schema.json) |
| [Q8](rest-api-requests.md#q8-unlink-a-commit) | DELETE | `/api/v1/requests/{requestId}/commits/{commitSha}` | B | 200 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [Q9](rest-api-requests.md#q9-get-a-commit-diff) | GET | `/api/v1/requests/{requestId}/commits/{commitSha}` | S, B | 200 | [`commit-diff.schema.json`](schemas/commit-diff.schema.json) |
| [Q10](rest-api-requests.md#q10-mark-ready-for-review) | POST | `/api/v1/requests/{requestId}/ready` | B | 200 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [Q11](rest-api-requests.md#q11-mark-wont-do) | POST | `/api/v1/requests/{requestId}/wont-do` | S, B | 200 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [Q12](rest-api-requests.md#q12-verify-a-request) | POST | `/api/v1/requests/{requestId}/verify` | S | 200 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [Q13](rest-api-requests.md#q13-reopen-a-request) | POST | `/api/v1/requests/{requestId}/reopen` | S | 200 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [Q14](rest-api-requests.md#q14-propose-a-solution) | POST | `/api/v1/requests/{requestId}/propose` | B | 200 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [Q15](rest-api-requests.md#q15-approve-a-proposal) | POST | `/api/v1/requests/{requestId}/approve-proposal` | S | 200 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [Q16](rest-api-requests.md#q16-reject-a-proposal) | POST | `/api/v1/requests/{requestId}/reject-proposal` | S | 200 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [Q17](rest-api-requests.md#q17-link-a-pull-request-to-requests) | POST | `/api/v1/pull-request-links` | B | 200 | [`link-pull-request-result.schema.json`](schemas/link-pull-request-result.schema.json) |
| [Q18](rest-api-requests.md#q18-change-the-plan-requirement) | POST | `/api/v1/requests/{requestId}/plan-requirement` | S | 200 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [Q19](rest-api-requests.md#q19-unlink-a-pull-request) | POST | `/api/v1/requests/{requestId}/unlink-pull-request` | B | 200 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [Q20](rest-api-requests.md#q20-suggest-a-request) | POST | `/api/v1/reviews/{reviewId}/suggestions` | B | 201 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [Q21](rest-api-requests.md#q21-accept-a-suggestion) | POST | `/api/v1/requests/{requestId}/accept-suggestion` | S | 200 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [Q22](rest-api-requests.md#q22-dismiss-a-suggestion) | POST | `/api/v1/requests/{requestId}/dismiss-suggestion` | S | 200 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [Q23](rest-api-requests.md#q23-set-the-title) | POST | `/api/v1/requests/{requestId}/title` | S, B | 200 | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) |
| [D1](rest-api-requests.md#d1-list-drafts) | GET | `/api/v1/reviews/{reviewId}/drafts` | S | 200 | [`lists.schema.json#/$defs/DraftList`](schemas/lists.schema.json) |
| [D2](rest-api-requests.md#d2-create-a-draft) | POST | `/api/v1/reviews/{reviewId}/drafts` | S | 201 | [`draft.schema.json`](schemas/draft.schema.json) |
| [D3](rest-api-requests.md#d3-update-a-draft) | PATCH | `/api/v1/drafts/{draftId}` | S | 200 | [`draft.schema.json`](schemas/draft.schema.json) |
| [D4](rest-api-requests.md#d4-delete-a-draft) | DELETE | `/api/v1/drafts/{draftId}` | S | 204 | none |
| [D5](rest-api-requests.md#d5-submit-drafts) | POST | `/api/v1/reviews/{reviewId}/drafts/submit` | S | 201 | [`batch-submission.schema.json`](schemas/batch-submission.schema.json) |
| [T1](rest-api-requests.md#t1-list-api-tokens) | GET | `/api/v1/tokens` | S | 200 | [`lists.schema.json#/$defs/TokenList`](schemas/lists.schema.json) |
| [T2](rest-api-requests.md#t2-create-an-api-token) | POST | `/api/v1/tokens` | S | 201 | [`api-token.schema.json#/$defs/ApiTokenWithSecret`](schemas/api-token.schema.json) |
| [T3](rest-api-requests.md#t3-revoke-an-api-token) | DELETE | `/api/v1/tokens/{tokenId}` | S | 204 | none |

Common errors are not repeated per endpoint:

- every endpoint: 401 `unauthorized`, 429 `rate_limited` of the limiters `api_token` and `api_user`, and 500 `internal_error`, as [README.md section 4](README.md#rate-limits) describes;
- 403 `forbidden`, as [README.md section 3](README.md#3-authentication-actors-and-scope) describes;
- 404 `not_found` for any ID that the caller cannot see;
- 400 or 422 `invalid_input` for malformed input.

The "Errors" lines below list only the specific cases.

## 3. Account and live-update authorization

### A1 Get the caller

- **Request:** `GET /api/v1/me`
- **Authentication:** S, B
- **Success:** 200 [`Me`](schemas/me.schema.json)
- **Errors:** common errors only.

The response holds:

- the signed-in user and the tenant;
- the calling actor, and the token for a bearer call;
- the server features: `localRepositories`, and `githubApp`. `githubApp` is null when the GitHub App is not configured or the user signed in locally.
- `plan`: the [plan of the tenant](webhooks-and-live-updates.md#marketplace-plans) with its name, the end of a free trial, a pending downgrade or cancellation, and the GitHub Marketplace listing. `listingUrl` is null when the instance has no listing.
- `usage`: the use of the tenant and the limit of its plan for each [usage limit](README.md#usage-limits). A token scoped to one repository also gets the use of the whole tenant.
- the links `mcpUrl`, `docsUrl`, and `skillUrl`.
- `preferences`: the [preferences](#a4-update-the-preferences) of the user. A bearer call gets those of the token owner.

Agents use this endpoint to check the scope of their token.

Example response for a bearer call, [fixtures/me.agent.json](fixtures/me.agent.json):

```json
{
  "user": {"id":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","login":"janklan","name":"Jan Klan","avatarUrl":"https://avatars.githubusercontent.com/u/1?v=4","signInMethod":"github"},
  "tenantId": "01K6AZZ0TENANT000000000001",
  "caller": {"kind":"agent","userId":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","agentName":"claude-code laptop","name":"claude-code laptop","avatarUrl":null},
  "token": {"id":"01K6C0T0KEN0000000000000AB","name":"claude-code laptop","scope":"repository","repositoryId":"01K6B2Z0R3P0S1T2V3W4X5Y6Z7","repositoryName":"acme/billing","prefix":"sm_KXidQQ","state":"active","createdAt":"2026-09-29T07:00:00Z","expiresAt":null,"lastUsedAt":"2026-09-29T07:30:00Z","revokedAt":null},
  "features": {"localRepositories":true,"githubApp":{"slug":"slopmower","installUrl":"https://github.com/apps/slopmower/installations/new"}},
  "plan": {"name":"Pro","trialEndsAt":"2026-10-13T00:00:00Z","pendingChange":{"name":"Team","effectiveAt":"2026-11-13T00:00:00Z"},"listingUrl":"https://github.com/marketplace/slopmower"},
  "usage": [{"limit":"repositories","max":100,"current":3},{"limit":"open_reviews","max":100,"current":2},{"limit":"requests_per_review","max":5000,"current":14},{"limit":"repository_size","max":4294967296,"current":48234496},{"limit":"tenant_storage","max":53687091200,"current":61865984}],
  "links": {"mcpUrl":"https://slopmower.example/_mcp","docsUrl":"https://slopmower.example/docs","skillUrl":"https://slopmower.example/docs/skill/SKILL.md"},
  "preferences": {"completedTours":["code_browser"]}
}
```

A session of a local sign-in, on an instance with the usage limits off: [fixtures/me.local-user.json](fixtures/me.local-user.json).

### A2 Delete the account

- **Request:** `DELETE /api/v1/account?confirm={login}`
- **Authentication:** S
- **Success:** 202, no body.
- **Errors:** 422 `invalid_input` when `confirm` does not equal `user.login`.

Right away, in the same request:

- the server revokes all tokens;
- it invalidates the session, and the response clears the cookie;
- it marks the tenant as being deleted. After that, a sign-in, a session, or a token of the tenant gets 401, and every other caller gets 404 for its IDs.

Then a Messenger job deletes all rows and files of the tenant, including the clones and their pin refs. The GitHub App stays installed until the user uninstalls it on GitHub.

### A3 Authorize live updates

- **Request:** `POST /api/v1/mercure/authorize`
- **Authentication:** S
- **Body:** none.
- **Success:** 200 [`MercureAuthorization`](schemas/lists.schema.json)
- **Errors:** common errors only.

The endpoint sets the Mercure subscriber cookie:

- the cookie name of the hub, by default `mercureAuthorization`;
- `HttpOnly`, `SameSite=Strict`, path `/.well-known/mercure`;
- `Secure` when the request uses HTTPS.

The `mercure.subscribe` claim of the JWT holds the two topic selectors of the tenant. `exp` is now plus 3,600 seconds. The UI calls this endpoint before it opens an EventSource, and again before `expiresAt`. See [webhooks-and-live-updates.md](webhooks-and-live-updates.md#3-live-updates).

Example response, [fixtures/lists.mercure-authorization.json](fixtures/lists.mercure-authorization.json):

```json
{
  "hubUrl": "https://slopmower.example/.well-known/mercure",
  "topics": ["https://slopmower.example/tenants/01K6AZZ0TENANT000000000001/repositories","https://slopmower.example/tenants/01K6AZZ0TENANT000000000001/reviews/{reviewId}"],
  "expiresAt": "2026-09-29T08:31:12Z"
}
```

### A4 Update the preferences

- **Request:** `PATCH /api/v1/me/preferences`
- **Authentication:** S
- **Body:** [`PreferencesInput`](schemas/inputs.schema.json), `{completedTours?}`. A list replaces the stored list. An absent or null `completedTours` keeps it. Example: [fixtures/inputs.preferences.json](fixtures/inputs.preferences.json).
- **Success:** 200 [`Preferences`](schemas/me.schema.json)
- **Errors:** 422 `invalid_input` for an unknown `Tour` name or a name twice.

The preferences belong to the account, not to a browser. `completedTours` lists the parts of the review tour that the user finished or dismissed, in the order of `Tour`. The web UI shows each other part, so a new user sees the whole tour, and `[]` shows it again. A stored name that the server no longer knows is left out of the responses. The write records no event and sends no live update.

Example response, [fixtures/me.preferences.json](fixtures/me.preferences.json):

```json
{"completedTours": ["code_browser"]}
```

## 4. Repositories

### R1 List repositories

- **Request:** `GET /api/v1/repositories`
- **Authentication:** S, B
- **Success:** 200 [`RepositoryPage`](schemas/lists.schema.json)
- **Errors:** common errors only.

| Query | Type | Default | Notes |
|---|---|---|---|
| `name` | string | none | Exact match after resolution. See [README.md section 7](README.md#7-repository-sources-names-and-commit-availability). Returns 0 or 1 items. An agent without MCP finds the repository ID this way. |
| `source` | `github`\|`local` | all | |
| `limit` | integer 1-500 | 100 | |
| `cursor` | Cursor | none | |

The list is sorted by `name` ascending, case-insensitive, then by `id`. A scoped token gets at most its own repository.

Example response: [fixtures/lists.repository-page.json](fixtures/lists.repository-page.json).

### R2 List repository candidates

- **Request:** `GET /api/v1/repositories/candidates`
- **Authentication:** S
- **Success:** 200 [`CandidateList`](schemas/lists.schema.json)
- **Errors:** common errors only.

The endpoint lists the repositories that the user can connect.

| Query | Type | Default | Notes |
|---|---|---|---|
| `source` | `github`\|`local` | all | |
| `q` | string | none | Case-insensitive substring of the name |
| `includeConnected` | boolean | true | |

- **GitHub candidates** come from the list per tenant that the server takes at the last sign-in. It calls `GET /user/installations` and `GET /user/installations/{id}/repositories` with the user token, which it never stores. It skips an installation whose `target_type` is neither `User` nor `Organization`, for example an enterprise installation. The `installation_repositories.added` webhook updates the list for installations on the own account of the user. To refresh the list, the user signs in again through `/connect/github`. The setup URL `/github/installed` does that automatically.
- **Local candidates** are scanned again on every call. The scan lists the direct children of `APP_LOCAL_REPOSITORIES_DIR`, skips symlinks and names that start with `.`, and keeps directories that hold a `.git` directory, or the `HEAD`, `objects`, and `refs` of a bare repository.
- The list holds at most 1,000 items, sorted by source and then by name. `truncated` is true when more exist.

Example response, [fixtures/lists.candidate-list.json](fixtures/lists.candidate-list.json):

```json
{
  "items": [
    {"source":"github","name":"acme/billing","connectedRepositoryId":"01K6B2Z0R3P0S1T2V3W4X5Y6Z7","github":{"repositoryId":812345,"installationId":5551,"accountLogin":"acme","owner":"acme","private":true,"defaultBranch":"main","htmlUrl":"https://github.com/acme/billing"},"local":null},
    {"source":"github","name":"acme/shipping","connectedRepositoryId":null,"github":{"repositoryId":812399,"installationId":5551,"accountLogin":"acme","owner":"acme","private":true,"defaultBranch":"develop","htmlUrl":"https://github.com/acme/shipping"},"local":null},
    {"source":"local","name":"billing","connectedRepositoryId":"01K6B2Z0R3P0S1T2V3W4X5Y6Z8","github":null,"local":{"directory":"billing","bare":false,"headBranch":"main"}},
    {"source":"local","name":"mirror.git","connectedRepositoryId":null,"github":null,"local":{"directory":"mirror.git","bare":true,"headBranch":null}}
  ],
  "truncated": false,
  "localEnabled": true,
  "githubListedAt": "2026-09-29T06:59:10Z",
  "githubConfigureUrl": "https://github.com/apps/slopmower/installations/new"
}
```

### R3 Connect a repository

- **Request:** `POST /api/v1/repositories`
- **Authentication:** S
- **Body:** [`ConnectRepositoryInput`](schemas/inputs.schema.json), either `{"source":"github","githubRepositoryId":812345}` or `{"source":"local","directory":"billing"}`.
- **Success:** 201 [`Repository`](schemas/repository.schema.json) with `Location: /api/v1/repositories/{id}` for a new repository. 200 with the existing repository when it is already connected.
- **Errors:**
  - 404 `not_found`: the GitHub ID is not a candidate, or the directory is not a local candidate.
  - 422 `invalid_input`: `source: local` while local repositories are off.
  - 422 `invalid_input`: another connected GitHub repository of the tenant has the same name, compared case-insensitively.
  - 409 `limit_exceeded` with `limit: repositories`: a new repository would pass the limit. A repository that is already connected gets 200.

A new repository starts with `sync.state = cloning`, and the server queues a clone job.

Example response, [fixtures/repository.github-cloning.json](fixtures/repository.github-cloning.json):

```json
{
  "id": "01K6B2Z0R3P0S1T2V3W4X5Y6Z7",
  "source": "github",
  "name": "acme/billing",
  "github": {"repositoryId":812345,"installationId":5551,"owner":"acme","private":true,"htmlUrl":"https://github.com/acme/billing","checkEveryPullRequest":false},
  "local": null,
  "defaultBranch": null,
  "access": "active",
  "sync": {"state":"cloning","lastFetchedAt":null,"lastError":null,"defaultBranchHead":null},
  "openReviewCount": 0,
  "createdAt": "2026-09-29T07:01:00Z"
}
```

A connected local repository after its first clone: [fixtures/repository.local-idle.json](fixtures/repository.local-idle.json).

### R4 Get a repository

- **Request:** `GET /api/v1/repositories/{repositoryId}`
- **Authentication:** S, B
- **Success:** 200 [`Repository`](schemas/repository.schema.json)
- **Errors:** common errors only.

### R5 Fetch a repository

- **Request:** `POST /api/v1/repositories/{repositoryId}/fetch`
- **Authentication:** S, B
- **Body:** none.
- **Success:** 202 [`Repository`](schemas/repository.schema.json), whose `sync.state` is `fetching` or `cloning`.
- **Errors:** 409 `repository_unavailable` with reason `removed`.

The endpoint queues a fetch. If `sync.state` is `failed`, it queues the clone again. A queued fetch or clone is reused. A call during a running fetch queues one more fetch. When the fetch finishes, the server publishes a `repository` live update. The "Refresh" button of the UI calls this endpoint.

### R6 Delete repository data

- **Request:** `DELETE /api/v1/repositories/{repositoryId}?confirm={name}`
- **Authentication:** S
- **Success:** 202, no body.
- **Errors:** 422 `invalid_input` when `confirm` does not equal `name`.

One transaction in the request deletes the repository and its rows: the reviews, the requests, the events, the drafts, the viewed files, the location cache, the index, the pull request records, and the tokens scoped to the repository. The repository and its reviews then get 404, and the server publishes `repository_deleted`.

Then a Messenger job deletes the clone with its pin refs. The directory or GitHub repository can be connected again at once. The GitHub App installation does not change.

### R7 Get a blob

- **Request:** `GET /api/v1/repositories/{repositoryId}/blobs/{blobSha}`
- **Authentication:** S, B
- **Success:** 200 with the bytes of one blob from the clone, or 304.
- **Errors:**
  - 404 `not_found`: the object is not in the clone, or it is not a blob;
  - 409 `repository_unavailable`.

The verify view uses this endpoint for the old and the new side of a `FileDiff`. The server streams the body from `git cat-file`. Response headers:

- `Content-Type`: `text/plain; charset=utf-8` for text, or `application/octet-stream` when the first 8,000 bytes contain a NUL byte;
- `Content-Length`;
- `X-Content-Type-Options: nosniff`;
- `Content-Security-Policy: sandbox; default-src 'none'`;
- caching as in [README.md section 6](README.md#6-caching-and-etags).

### R8 Check every pull request

- **Request:** `POST /api/v1/repositories/{repositoryId}/check-every-pull-request`
- **Authentication:** S
- **Body:** [`CheckEveryPullRequestInput`](schemas/inputs.schema.json), `{"enabled": true}`.
- **Success:** 200 [`Repository`](schemas/repository.schema.json) with the new `github.checkEveryPullRequest`.
- **Errors:** 422 `invalid_input` for a local repository, which has no check runs.

The value says which pull requests of a GitHub repository get the check of the user, see [webhooks-and-live-updates.md](webhooks-and-live-updates.md#check-run). Off, the default: only the pull requests that a request of the tenant links. On: every pull request, and the others get a `neutral` run, so a required check does not block them. A change publishes the live update `repository`. No MCP tool changes the value.

### R9 List the latest commits

- **Request:** `GET /api/v1/repositories/{repositoryId}/commits?ref=&limit=`
- **Authentication:** S, B
- **Success:** 200 [`CommitLog`](schemas/lists.schema.json)
- **Errors:**
  - 422 `invalid_input`: a malformed `ref`, a `limit` outside 1 to 50, or no `ref` while the repository has no default branch;
  - 409 `repository_unavailable`: `cloning`, `clone_failed`, or `removed`;
  - 422 `commit_not_found`: the ref is not in the server clone;
  - 422 `invalid_input`: an ambiguous SHA prefix.

| Query | Type | Default | Notes |
|---|---|---|---|
| `ref` | string, 1 to 255 characters | the default branch | A branch, a tag, or a SHA. The ref validation of [V1](#v1-start-a-review). |
| `limit` | integer 1-50 | 10 | |

Rules:

- The server reads the clone as it is and does not fetch, see decision D76 in [architecture.md](../architecture.md). `sync.lastFetchedAt` of the repository is the time of that state. [R5](#r5-fetch-a-repository) fetches.
- Without `ref`, the server resolves `refs/heads/<defaultBranch>`, so the first item is `sync.defaultBranchHead`, and `ref` is the name of the branch. With `ref`, it resolves `git rev-parse --verify --end-of-options <ref>^{commit}`, as V1 does, and `ref` is the value as given.
- `items` holds the first `limit` commits of `git log` from the resolved commit, newest first, in the default order of `git log` through all parents. The list is not paginated.
- `subject` is the subject of the commit message, `%s` of `git log`: its first paragraph on one line. `authorName` is the author name as the commit records it, without a mailmap, as in [V17](#v17-get-the-blame-of-a-file). Invalid UTF-8 is replaced.

Example response, [fixtures/lists.commit-list.json](fixtures/lists.commit-list.json):

```json
{
  "repositoryId": "01K6B2Z0R3P0S1T2V3W4X5Y6Z7",
  "ref": "main",
  "items": [
    {"sha": "79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091", "subject": "Round tax per line", "authorName": "Grace Hopper", "authoredAt": "2026-09-28T16:40:12Z", "committedAt": "2026-09-28T16:42:03Z"},
    {"sha": "1a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d", "subject": "Add invoice totals", "authorName": "Ada Lovelace", "authoredAt": "2026-03-02T09:14:05Z", "committedAt": "2026-03-02T09:14:05Z"}
  ]
}
```

## 5. Reviews and code

### V1 Start a review

- **Request:** `POST /api/v1/repositories/{repositoryId}/reviews`
- **Authentication:** S, B
- **Body:** [`StartReviewInput`](schemas/inputs.schema.json), for example `{"ref":"main","title":null}`.
  - `ref` defaults to the default branch.
  - `title` defaults to `"<ref> @ <sha 12 chars>"`, or to `"<sha 12 chars>"` when `ref` is a full SHA of 40 or 64 characters. The `<ref>` part is cut so that the title has at most 200 characters.
- **Success:** 201 [`Review`](schemas/review.schema.json) with `Location: /api/v1/reviews/{id}`.
- **Errors:**
  - 409 `repository_unavailable`: `cloning`, `clone_failed`, `fetch_failed`, or `removed`;
  - 422 `commit_not_found`: the ref cannot be resolved after the fetch;
  - 422 `invalid_input`: an ambiguous SHA prefix, a malformed ref, or no `ref` while the repository has no default branch;
  - 429 `rate_limited`: the `review_start` limit of the tenant, or the `sync_fetch` limit of the repository;
  - 409 `limit_exceeded` with `limit: open_reviews`. Example: [fixtures/error.limit-exceeded.json](fixtures/error.limit-exceeded.json).

Ref validation: the git ref-name rules of `git check-ref-format --allow-onelevel`, or a hex SHA of 7 to 64 characters. A leading `-` is refused.

An agent starts a review to suggest changes ([Q20](rest-api-requests.md#q20-suggest-a-request)). A token sees only the repositories of its scope, so a repository outside the scope gets 404 `not_found`. `startedBy` is the caller: the user for a session, the agent for a token. Only the user closes a review ([V4](#v4-close-a-review)).

Steps:

1. If `ref` is a full SHA that is already in the clone, no fetch runs. Otherwise the server fetches synchronously, with a 60-second timeout.
2. It resolves `git rev-parse --verify --end-of-options <ref>^{commit}` in the clone.
3. It writes the pin ref `refs/slopmower/reviews/<reviewId>` to the resolved commit.
4. It writes `review.started` with the caller as actor and the payload `title`, `ref`, `commitSha`.
5. It queues the index job, with `index.state = pending`.

Several open reviews of one repository are allowed, also at the same commit. They are fully separate: each request, draft, viewed-file mark, and cursor belongs to one review. The index is shared per commit inside a repository, because it is code data, not review data.

Example response, [fixtures/review.open.json](fixtures/review.open.json):

```json
{
  "id": "01K6B3A1B2C3D4E5F6G7H8J9KM",
  "repositoryId": "01K6B2Z0R3P0S1T2V3W4X5Y6Z7",
  "repositoryName": "acme/billing",
  "title": "main @ 79f8595a4c1e",
  "ref": "main",
  "commitSha": "79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091",
  "state": "open",
  "startedBy": {"kind":"user","userId":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","agentName":null,"name":"janklan","avatarUrl":"https://avatars.githubusercontent.com/u/1?v=4"},
  "createdAt": "2026-09-29T07:05:00Z",
  "closedAt": null,
  "closedBy": null,
  "closeNote": null,
  "index": {"state":"pending","filesTotal":null,"filesDone":0,"symbolsTotal":0,"startedAt":null,"finishedAt":null,"error":null},
  "counts": {"total":0,"byStatus":{"open":0,"in_progress":0,"ready_for_review":0,"verified":0,"reopened":0,"wont_do":0},"byAwaiting":{"agent":0,"user":0,"none":0},"outdated":0},
  "lastEventSeq": 1801
}
```

### V2 List reviews

- **Request:** `GET /api/v1/repositories/{repositoryId}/reviews`
- **Authentication:** S, B
- **Success:** 200 [`ReviewPage`](schemas/lists.schema.json), which includes the `repository` object.
- **Errors:** common errors only.

| Query | Type | Default | Notes |
|---|---|---|---|
| `state` | `open`\|`closed`\|`all` | `open` | |
| `limit` | integer 1-100 | 50 | |
| `cursor` | Cursor | none | |

The list is sorted by `createdAt` descending, then by `id` descending.

Example response: [fixtures/lists.review-page.json](fixtures/lists.review-page.json).

### V3 Get a review

- **Request:** `GET /api/v1/reviews/{reviewId}`
- **Authentication:** S, B
- **Success:** 200 [`Review`](schemas/review.schema.json), with counts and index state.
- **Errors:** common errors only.

Examples: [fixtures/review.open.json](fixtures/review.open.json), [fixtures/review.closed.json](fixtures/review.closed.json).

### V4 Close a review

- **Request:** `POST /api/v1/reviews/{reviewId}/close`
- **Authentication:** S
- **Body:** [`CloseReviewInput`](schemas/inputs.schema.json), `{"note":"Shipped in 2.4"}`. The note is optional.
- **Success:** 200 [`Review`](schemas/review.schema.json). Example: [fixtures/review.closed.json](fixtures/review.closed.json).
- **Errors:** 409 `review_closed` when the review is already closed.

One transaction:

- writes `review.closed` with actor user and the payload `{note}`;
- sets `closedAt`, `closedBy`, and `closeNote`;
- deletes the drafts of the caller in this review. The UI warns before it closes a review with drafts.

After the close:

- Every write on the review or its requests gets 409 `review_closed`. That covers commands, comments, new requests, viewed files, and the draft operations create, update, and submit. A carry-over into the review ([V15](#v15-carry-over-open-requests)) gets 422 `invalid_input` instead.
- Reads and draft deletion still work.
- Claims stay as they are.
- Reopening a closed review is not possible. It is a backlog item.

### V5 Get the index status

- **Request:** `GET /api/v1/reviews/{reviewId}/index`
- **Authentication:** S, B
- **Success:** 200 [`IndexStatus`](schemas/review.schema.json), the same object as `Review.index`. Example: [fixtures/review.index-status.json](fixtures/review.index-status.json).
- **Errors:** common errors only.

### V6 List a tree

- **Request:** `GET /api/v1/reviews/{reviewId}/tree`
- **Authentication:** S, B
- **Success:** 200 [`TreeListing`](schemas/lists.schema.json)
- **Errors:**
  - 404 `not_found`: no entry has the path `dir` at the review commit;
  - 422 `invalid_input`: `dir` names a file, a symlink, or a submodule;
  - 409 `repository_unavailable`.

| Query | Type | Default | Notes |
|---|---|---|---|
| `dir` | RepoPath or `""` | `""`, the root | Must name a directory at the review commit. A file, a symlink, or a submodule gets 422. |
| `recursive` | boolean | false | false: the direct entries, directories first, then byte order of the name. true: every file, symlink, and submodule under `dir`, by path in byte order, without directory entries. |
| `limit` | integer 1-5000 | 1000 | |
| `cursor` | Cursor | none | |

The listing leaves out entries whose path is not a valid RepoPath, for example a path that is not UTF-8 or that starts with `-`.

The server reads `git ls-tree` at the pinned commit.

Example response, [fixtures/lists.tree-listing.json](fixtures/lists.tree-listing.json):

```json
{
  "reviewId": "01K6B3A1B2C3D4E5F6G7H8J9KM",
  "commitSha": "79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091",
  "dir": "src/Billing",
  "recursive": false,
  "items": [
    {"path":"src/Billing/Tax","name":"Tax","type":"directory","mode":"040000","sha":"8a1f0c6d2e4b5a7c9d3e1f0a2b4c6d8e0f1a3b5c","sizeBytes":null},
    {"path":"src/Billing/InvoiceTotals.php","name":"InvoiceTotals.php","type":"file","mode":"100644","sha":"3b18e512dba79e4c8300dd08aeb37f8e728b8dad","sizeBytes":2210}
  ],
  "nextCursor": null
}
```

### V7 Get file metadata

- **Request:** `GET /api/v1/reviews/{reviewId}/file?path=`
- **Authentication:** S, B
- **Success:** 200 [`FileContent`](schemas/file-content.schema.json), or 304.
- **Errors:**
  - 404 `not_found`: no such path at the review commit;
  - 422 `invalid_input`: the path is a directory or a submodule;
  - 409 `repository_unavailable`.

Rules:

- `binary` follows the rule of git: a NUL byte in the first 8,000 bytes.
- `tooLarge` means `sizeBytes > 1048576`.
- `renderable = type == file && !binary && !tooLarge`. It does not check the encoding: a file that is not valid UTF-8 is renderable, but takes no `line`, `range`, or `symbol` anchor, see [Q2](rest-api-requests.md#q2-open-a-request).
- Every file takes file comments.

Example response, [fixtures/file-content.php.json](fixtures/file-content.php.json):

```json
{
  "reviewId": "01K6B3A1B2C3D4E5F6G7H8J9KM",
  "commitSha": "79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091",
  "path": "src/Billing/InvoiceTotals.php",
  "blobSha": "3b18e512dba79e4c8300dd08aeb37f8e728b8dad",
  "mode": "100644",
  "type": "file",
  "sizeBytes": 2210,
  "language": "php",
  "binary": false,
  "tooLarge": false,
  "renderable": true,
  "lineCount": 74,
  "symlinkTarget": null,
  "rawUrl": "/api/v1/reviews/01K6B3A1B2C3D4E5F6G7H8J9KM/raw?path=src%2FBilling%2FInvoiceTotals.php"
}
```

### V8 Get raw file content

- **Request:** `GET /api/v1/reviews/{reviewId}/raw?path=`
- **Authentication:** S, B
- **Success:** 200 with the bytes of the file at the review commit, or 304.
- **Errors:** as [V7](#v7-get-file-metadata).

Rules:

- Headers, caching, and streaming are the same as for [R7](#r7-get-a-blob).
- A symlink returns its target path as text.
- The endpoint serves any size. The `tooLarge` limit of 1 MiB applies only to rendering.

```http
GET /api/v1/reviews/01K6B3A1B2C3D4E5F6G7H8J9KM/raw?path=src%2FBilling%2FInvoiceTotals.php
If-None-Match: "3b18e512dba79e4c8300dd08aeb37f8e728b8dad"

HTTP/1.1 304 Not Modified
ETag: "3b18e512dba79e4c8300dd08aeb37f8e728b8dad"
Cache-Control: private, max-age=31536000, immutable
Vary: Authorization, Cookie
```

### V9 List symbols

- **Request:** `GET /api/v1/reviews/{reviewId}/symbols?path=`
- **Authentication:** S, B
- **Success:** 200 [`SymbolList`](schemas/lists.schema.json), or 304. The ETag is `W/"v5-<blobSha>"`, see [README section 6](README.md#6-caching-and-etags).
- **Errors:** as [V7](#v7-get-file-metadata).

Rules:

- The server parses the files whose [V7](#v7-get-file-metadata) `language` is `php`, `javascript`, `jsx`, `typescript`, `tsx`, `python`, `go`, `rust`, `java`, `csharp`, `ruby`, `shellscript`, `scala`, `zig`, `lua`, or `twig`, and returns that `language`. It reads the symbols from the `symbol` table by blob SHA. If the blob is not indexed yet, or is indexed for another language, the server parses it synchronously. It stores the result only for a blob that is not indexed. So this endpoint never waits for the index job.
- Other files return `language: null` and `items: []`. A file of one of these languages that is not renderable, see [V7](#v7-get-file-metadata), returns its `language`, `parseError: false`, and `items: []`. The extension match ignores case.
- A parse error returns `parseError: true` and `items: []`. A parse that takes more than 2 seconds is a parse error.
- `range` includes the doc comment. See decision D7 in [architecture.md](../architecture.md). The FQN format of each language is in [architecture.md](../architecture.md#symbol-languages).
- A failure of the symbol service answers 500 `internal_error` for a file that is neither PHP nor Twig. Nothing is stored.
- Items are sorted by `range.startLine`, then by `fqn`.

Example response, [fixtures/lists.symbol-list.json](fixtures/lists.symbol-list.json):

```json
{
  "reviewId": "01K6B3A1B2C3D4E5F6G7H8J9KM",
  "path": "src/Billing/InvoiceTotals.php",
  "blobSha": "3b18e512dba79e4c8300dd08aeb37f8e728b8dad",
  "language": "php",
  "parseError": false,
  "items": [
    {"kind":"class","fqn":"App\\Billing\\InvoiceTotals","name":"InvoiceTotals","parentFqn":null,"range":{"startLine":9,"endLine":74},"declarationStartLine":9,"docStartLine":null},
    {"kind":"property","fqn":"App\\Billing\\InvoiceTotals::$taxRate","name":"taxRate","parentFqn":"App\\Billing\\InvoiceTotals","range":{"startLine":12,"endLine":12},"declarationStartLine":12,"docStartLine":null},
    {"kind":"method","fqn":"App\\Billing\\InvoiceTotals::total()","name":"total","parentFqn":"App\\Billing\\InvoiceTotals","range":{"startLine":38,"endLine":49},"declarationStartLine":41,"docStartLine":38},
    {"kind":"method","fqn":"App\\Billing\\InvoiceTotals::tax()","name":"tax","parentFqn":"App\\Billing\\InvoiceTotals","range":{"startLine":51,"endLine":58},"declarationStartLine":51,"docStartLine":null}
  ]
}
```

### V10 Get updates

`GET /api/v1/reviews/{reviewId}/updates`, S and B, 200 [`UpdatesPage`](schemas/updates-page.schema.json). The query parameters and the algorithm are in [rest-api-requests.md section 4](rest-api-requests.md#4-updates).

### V11 List viewed files

- **Request:** `GET /api/v1/reviews/{reviewId}/viewed-files`
- **Authentication:** S
- **Success:** 200 [`ViewedFiles`](schemas/lists.schema.json) with the viewed paths of the caller in this review.
- **Errors:** common errors only.

The "Viewed" marks never reset inside a review.

Example response, [fixtures/lists.viewed-files.json](fixtures/lists.viewed-files.json):

```json
{
  "reviewId": "01K6B3A1B2C3D4E5F6G7H8J9KM",
  "paths": ["src/Billing/InvoiceTotals.php","src/Billing/Tax/TaxRate.php"]
}
```

### V12 Mark a file as viewed

- **Request:** `PUT /api/v1/reviews/{reviewId}/viewed-files`
- **Authentication:** S
- **Body:** [`ViewedFileInput`](schemas/inputs.schema.json), `{"path":"src/Billing/InvoiceTotals.php","viewed":true}`. `viewed: false` removes the mark of the path.
- **Success:** 200 [`ViewedFiles`](schemas/lists.schema.json)
- **Errors:**
  - 422 `invalid_input`: the path is not a file at the review commit;
  - 409 `review_closed`;
  - 409 `repository_unavailable`: the path check needs the clone.

The call is idempotent per path.

### V13 Delete a review

- **Request:** `DELETE /api/v1/reviews/{reviewId}`
- **Authentication:** S
- **Success:** 204, no body.
- **Errors:** common errors only.

The endpoint works for open and closed reviews, and for reviews of a repository whose access was removed. The UI asks for a confirmation in a dialog first.

- One transaction deletes the review and all its rows: requests, comments, commit links, events, the location cache entries, the drafts of every user, and the viewed-file marks.
- After the commit, the server deletes `refs/slopmower/reviews/<reviewId>` and each `refs/slopmower/commits/<sha>` that no remaining link in the repository uses, when the clone exists.
- The index stays, because it belongs to the repository and the commit, not to the review.
- The server publishes a `repository` live update, because `openReviewCount` can change.
- Afterwards the review ID and the IDs of its requests and drafts get 404.

### V14 Find declarations

- **Request:** `GET /api/v1/reviews/{reviewId}/declarations?path=&line=&column=` for a name at a position, or `GET /api/v1/reviews/{reviewId}/declarations?path=&name=` for a typed name.
- **Authentication:** S, B
- **Success:** 200 [`DeclarationList`](schemas/lists.schema.json)
- **Errors:**
  - 404 `not_found`: no such path at the review commit;
  - 422 `invalid_input`: the path is not a PHP file, `*.php`, or it is a directory or a submodule; the query has neither `line` and `column` nor `name`, or it has both; `name` is not a PHP name;
  - 409 `repository_unavailable`.

| Query | Type | Notes |
|---|---|---|
| `path` | RepoPath | Required. The file that contains the name. The extension match ignores case. |
| `line` | integer, 1 or more | With `column` |
| `column` | integer, 1 or more | Counts the Unicode characters of the line, from 1 |
| `name` | string, 1 to 1024 characters | `Type`, `Ns\Type`, `\Ns\Type`, `namespace\Type`, `function`, `CONSTANT`, `Type::member`, `Type::method()`, or `Type::$property` |

Rules:

- The server parses the file at the review commit and resolves the name statically, as PHP does: with the namespace and the `use` imports of the file.
- A position resolves the name that covers it:
  - a class-like name in any position, also in a `use` import;
  - the member of `Type::method()`, `Type::CONSTANT`, `Type::Case`, and `Type::$property`;
  - a function call and a constant;
  - `self` and `static` give the enclosing class-like, `parent` gives the parent class.
- Any other position gives no targets. For example, a method call on a variable, `$x->foo()`, needs type inference.
- `name` resolves in the context of the file. A file with several namespaces uses its last one. A leading `\` makes the name fully qualified, and a leading `namespace\` makes it relative to the namespace of the file.
  - A name without `::` matches a class-like, a function, and a constant.
  - In `Type::member`, the class part resolves as a class name. `member()` matches a method, `$member` a property, and `member` a method, a class constant, or an enum case.
- An unqualified function or constant name that no `use` imports resolves in the namespace of the file first, then in the global namespace, as PHP falls back at run time.
- The targets are the declarations of the resolved names among the PHP symbols of the review commit, see [V9](#v9-list-symbols). FQNs compare exactly, also in case.
- While the index job runs, see [V5](#v5-get-the-index-status), a file that the job has not parsed yet gives no targets.
- A name without a declaration in the commit, for example a vendor class that is not committed, gives `items: []`. So do a file that is not renderable, see [V7](#v7-get-file-metadata), a file that does not parse, and a position without a name.
- `range` and `declarationStartLine` are those of the symbol in V9. `range` starts at the doc comment, if any. `declarationStartLine` is the first line of the declaration, attributes included, and the UI jumps to it.
- Items are sorted by `path` in byte order, then by `range.startLine`, then by `fqn`. The list is not paginated.

Example response to `?path=src%2FBilling%2FInvoiceTotals.php&line=12&column=24`, [fixtures/lists.declaration-list.json](fixtures/lists.declaration-list.json):

```json
{
  "reviewId": "01K6B3A1B2C3D4E5F6G7H8J9KM",
  "items": [
    {"path":"src/Billing/Tax/TaxRate.php","fqn":"App\\Billing\\Tax\\TaxRate","kind":"class","range":{"startLine":7,"endLine":31},"declarationStartLine":10}
  ]
}
```

### V15 Carry over open requests

- **Request:** `POST /api/v1/reviews/{reviewId}/carry-over`
- **Authentication:** S
- **Body:** [`CarryOverInput`](schemas/inputs.schema.json), `{"fromReviewId":"01K6B3A1B2C3D4E5F6G7H8J9KM"}`. Example: [fixtures/inputs.carry-over.json](fixtures/inputs.carry-over.json).
- **Success:** 200 [`CarryOverResult`](schemas/carry-over-result.schema.json). Example: [fixtures/carry-over-result.mixed.json](fixtures/carry-over-result.mixed.json).
- **Errors:**
  - 404 `not_found`: the caller cannot see the review `fromReviewId`;
  - 422 `invalid_input`: `fromReviewId` is the review itself or a review of another repository, or the review `{reviewId}` is closed;
  - 409 `repository_unavailable`: a source request has an anchor, and the clone is not ready;
  - 409 `limit_exceeded` with `limit: requests_per_review`: the carried requests would pass the limit of the target review.

The endpoint copies the open requests of the source review into the target review `{reviewId}`, at the target review commit. The web UI calls it after [V1](#v1-start-a-review). It also works later on any open review.

- The source review can be open or closed. The call changes neither it nor its requests.
- `items` holds one item per request of the source review, sorted by `id`. `source` is the source request.
- A source request with status `open` or `reopened` is carried. Any other status gives `not_carried`, with the status as `reason`: an `in_progress` request must be released first, and requests that wait for the user or are closed stay.
- A pending suggestion is not carried. It gives `not_carried` with the reason `open`, and its `source.suggestion` tells the client why. An accepted suggestion is carried as other requests. The copy has the caller as author, so it is a request of the user with `suggestion` null.
- A source request that has a carried copy in the target review gives `already_carried` with the `requestId` of that copy. A repeated call writes nothing for it.
- The server locates each anchor at the target review commit with `locate` of [architecture.md](../architecture.md) section 5.4, then captures the new anchor at the target review commit as [Q2](rest-api-requests.md#q2-open-a-request) does. `location` is the status of that location.

| Location | New anchor | First comment |
|---|---|---|
| `exact`, `shifted` | The same kind, at the located path and range, with a new snapshot | None |
| `changed` | The same kind, at the located path and range, with a new snapshot | The code changed between the two review commits |
| `orphaned`, and the file still exists, also after a rename | A `file` anchor on that path | The code of the old snapshot, with its commit, path, and lines |
| `orphaned`, and the file is gone | None: `not_carried`, with the orphan reason as `reason` | None |

- "The same kind": a `file` anchor stays a `file` anchor. A `line` or `range` anchor becomes `line` for one located line and `range` for more. A `symbol` anchor stays `symbol` when its FQN names exactly one symbol of the file at the target review commit, else it becomes `line` or `range` at the located range. When the capture fails, for example because the file is no longer valid UTF-8, the request gets the `file` anchor and the comment of an orphaned location.
- A general request carries as a general request, with `location` null.
- One transaction under the tenant lock writes, per carried request, `request.opened` with the source body, the source title, the new anchor, `batchId` null, and `carriedFrom`, followed by `request.commented` with the first comment, if any. Both have the caller as actor. A carried request starts as `open`. Then one `events` live update follows.
- [Q3](rest-api-requests.md#q3-get-a-request) and every `RequestSummary` show `carriedFrom` of a carried request: the `reviewId` and `requestId` of its source.

### V16 Search files and symbols

- **Request:** `GET /api/v1/reviews/{reviewId}/search?q=&limit=`
- **Authentication:** S, B
- **Success:** 200 [`SearchHitList`](schemas/lists.schema.json)
- **Errors:** 422 `invalid_input`: `q` is missing, empty, longer than 1,024 characters, or holds a NUL byte, or `limit` is outside 1 to 50.

| Query | Type | Default | Notes |
|---|---|---|---|
| `q` | string, 1 to 1024 characters | none | Required. A part of a path or of a symbol name, or word starts of a name. |
| `limit` | integer 1-50 | 20 | |

Rules:

- The server reads the index of the review commit, see [V5](#v5-get-the-index-status): the paths of `indexed_file` and the symbols of their blobs, in every language of [V9](#v9-list-symbols). It does not read the clone.
- The words of a name are split at every character that is not a letter or a digit, before an upper-case letter that follows a lower-case letter or a digit, and before an upper-case letter that follows an upper-case letter and precedes a lower-case letter. So `HTTPClient` has the words `HTTP` and `Client`.
- A name matches `q` by word starts when `q` is a sequence of non-empty prefixes of its words, in word order, with words skipped where needed. `CE` and `ComEx` match `CommandExecutor`, `tax` matches `InvoiceTax`, and `ce` does not match `Service`. A `q` with a character that is not a letter or a digit matches no name by word starts.
- A file hit is a file or a symlink whose path contains `q`, or whose name matches `q` by word starts. The name of a file is the last segment of its path. Submodules give no hit.
- A symbol hit is a symbol whose name contains `q` or matches it by word starts. The name is the short name of [V9](#v9-list-symbols), for example `total` for `App\Billing\InvoiceTotals::total()` or for `InvoiceTotals.total()`.
- The match ignores case. `q` has no wildcards.
- Order:
  1. by the name: equal to `q`, then starting with `q`, then matching `q` by word starts, then containing `q`, then a file whose path contains `q` and whose name matches neither way;
  2. by the length of the name, shorter first;
  3. by the length of `path`, shorter first;
  4. by `path` in byte order;
  5. files before symbols, then by `declarationStartLine`, then by `fqn` in byte order.
- Lengths count characters.
- The list holds the first `limit` hits. It is not paginated.
- `partial` is true while the index state is not `ready`. The hits then come from what the index job has written: a file that the job has not listed or parsed yet gives no hit.

Example response to `?q=taxrate`, [fixtures/lists.search-hit-list.json](fixtures/lists.search-hit-list.json):

```json
{
  "reviewId": "01K6B3A1B2C3D4E5F6G7H8J9KM",
  "partial": false,
  "items": [
    {"kind":"symbol","path":"src/Billing/Tax/TaxRate.php","fqn":"App\\Billing\\Tax\\TaxRate","symbolKind":"class","declarationStartLine":10},
    {"kind":"symbol","path":"src/Billing/InvoiceTotals.php","fqn":"App\\Billing\\InvoiceTotals::$taxRate","symbolKind":"property","declarationStartLine":12},
    {"kind":"file","path":"src/Billing/Tax/TaxRate.php"}
  ]
}
```

### V17 Get the blame of a file

- **Request:** `GET /api/v1/reviews/{reviewId}/blame?path=`
- **Authentication:** S, B
- **Success:** 200 [`Blame`](schemas/blame.schema.json), or 304.
- **Errors:**
  - 404 `not_found`: no such path at the review commit;
  - 422 `invalid_input`: the path is a directory or a submodule, or the file is not renderable, see [V7](#v7-get-file-metadata): a symlink, a binary file, or a file above 1 MiB;
  - 409 `repository_unavailable`.

Rules:

- The server runs `git blame` with the review commit as the revision, see decision D59 in [architecture.md](../architecture.md). Each line names the commit that last changed it in the history of the review commit. git follows a renamed file.
- `ranges` covers every line, from line 1 to the last line, in ascending order. Adjacent lines of one commit form one range.
- `commits` holds each commit of `ranges` once, in the order of its first range. `authorName` is the author name as the commit records it, without a mailmap: a mailmap of the bare clone follows its `HEAD`, which a fetch moves. `summary` is the first line of the commit message. Invalid UTF-8 is replaced.
- When `.git-blame-ignore-revs` exists at the root of the review commit, the server ignores the commits that it names, as GitHub does, and `ignoreRevsFile` is true. Only lines that are a full commit SHA count. Comments, blank lines, other text, and commits that the repository lacks are skipped. A file without such a line gives `ignoreRevsFile: false`.
- Caching as for V7, with the ETag `W/"v1-<commitSha>-<blobSha>"`. See [README.md section 6](README.md#6-caching-and-etags).

Example response, [fixtures/blame.php.json](fixtures/blame.php.json):

```json
{
  "reviewId": "01K6B3A1B2C3D4E5F6G7H8J9KM",
  "commitSha": "79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091",
  "path": "src/Billing/InvoiceTotals.php",
  "blobSha": "3b18e512dba79e4c8300dd08aeb37f8e728b8dad",
  "ignoreRevsFile": false,
  "ranges": [
    {"startLine": 1, "endLine": 37, "commitSha": "1a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d"},
    {"startLine": 38, "endLine": 49, "commitSha": "79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091"},
    {"startLine": 50, "endLine": 74, "commitSha": "1a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d"}
  ],
  "commits": [
    {"sha": "1a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d", "authorName": "Ada Lovelace", "authoredAt": "2026-03-02T09:14:05Z", "summary": "Add invoice totals"},
    {"sha": "79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091", "authorName": "Grace Hopper", "authoredAt": "2026-09-28T16:40:12Z", "summary": "Round tax per line"}
  ]
}
```
