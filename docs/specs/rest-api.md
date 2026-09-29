# Holistic Review REST API v1

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
| [R1](#r1-list-repositories) | GET | `/api/v1/repositories` | S, B | 200 | [`lists.schema.json#/$defs/RepositoryPage`](schemas/lists.schema.json) |
| [R2](#r2-list-repository-candidates) | GET | `/api/v1/repositories/candidates` | S | 200 | [`lists.schema.json#/$defs/CandidateList`](schemas/lists.schema.json) |
| [R3](#r3-connect-a-repository) | POST | `/api/v1/repositories` | S | 201 or 200 | [`repository.schema.json`](schemas/repository.schema.json) |
| [R4](#r4-get-a-repository) | GET | `/api/v1/repositories/{repositoryId}` | S, B | 200 | [`repository.schema.json`](schemas/repository.schema.json) |
| [R5](#r5-fetch-a-repository) | POST | `/api/v1/repositories/{repositoryId}/fetch` | S, B | 202 | [`repository.schema.json`](schemas/repository.schema.json) |
| [R6](#r6-delete-repository-data) | DELETE | `/api/v1/repositories/{repositoryId}?confirm={name}` | S | 202 | none |
| [R7](#r7-get-a-blob) | GET | `/api/v1/repositories/{repositoryId}/blobs/{blobSha}` | S, B | 200 or 304 | bytes |
| [V1](#v1-start-a-review) | POST | `/api/v1/repositories/{repositoryId}/reviews` | S | 201 | [`review.schema.json`](schemas/review.schema.json) |
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
| [D1](rest-api-requests.md#d1-list-drafts) | GET | `/api/v1/reviews/{reviewId}/drafts` | S | 200 | [`lists.schema.json#/$defs/DraftList`](schemas/lists.schema.json) |
| [D2](rest-api-requests.md#d2-create-a-draft) | POST | `/api/v1/reviews/{reviewId}/drafts` | S | 201 | [`draft.schema.json`](schemas/draft.schema.json) |
| [D3](rest-api-requests.md#d3-update-a-draft) | PATCH | `/api/v1/drafts/{draftId}` | S | 200 | [`draft.schema.json`](schemas/draft.schema.json) |
| [D4](rest-api-requests.md#d4-delete-a-draft) | DELETE | `/api/v1/drafts/{draftId}` | S | 204 | none |
| [D5](rest-api-requests.md#d5-submit-drafts) | POST | `/api/v1/reviews/{reviewId}/drafts/submit` | S | 201 | [`batch-submission.schema.json`](schemas/batch-submission.schema.json) |
| [T1](rest-api-requests.md#t1-list-api-tokens) | GET | `/api/v1/tokens` | S | 200 | [`lists.schema.json#/$defs/TokenList`](schemas/lists.schema.json) |
| [T2](rest-api-requests.md#t2-create-an-api-token) | POST | `/api/v1/tokens` | S | 201 | [`api-token.schema.json#/$defs/ApiTokenWithSecret`](schemas/api-token.schema.json) |
| [T3](rest-api-requests.md#t3-revoke-an-api-token) | DELETE | `/api/v1/tokens/{tokenId}` | S | 204 | none |

Common errors are not repeated per endpoint:

- every endpoint: 401 `unauthorized` and 500 `internal_error`;
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
- the links `mcpUrl`, `docsUrl`, and `skillUrl`.

Agents use this endpoint to check the scope of their token.

Example response for a bearer call, [fixtures/me.agent.json](fixtures/me.agent.json):

```json
{
  "user": {"id":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","login":"janklan","name":"Jan Klan","avatarUrl":"https://avatars.githubusercontent.com/u/1?v=4","signInMethod":"github"},
  "tenantId": "01K6AZZ0TENANT000000000001",
  "caller": {"kind":"agent","userId":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","agentName":"claude-code laptop","name":"claude-code laptop","avatarUrl":null},
  "token": {"id":"01K6C0T0KEN0000000000000AB","name":"claude-code laptop","scope":"repository","repositoryId":"01K6B2Z0R3P0S1T2V3W4X5Y6Z7","repositoryName":"acme/billing","prefix":"hr_KXidQQ","state":"active","createdAt":"2026-09-29T07:00:00Z","expiresAt":null,"lastUsedAt":"2026-09-29T07:30:00Z","revokedAt":null},
  "features": {"localRepositories":false,"githubApp":{"slug":"holistic-review","installUrl":"https://github.com/apps/holistic-review/installations/new"}},
  "links": {"mcpUrl":"https://hr.example/_mcp","docsUrl":"https://hr.example/docs","skillUrl":"https://hr.example/docs/skill/SKILL.md"}
}
```

A session of a local sign-in: [fixtures/me.local-user.json](fixtures/me.local-user.json).

### A2 Delete the account

- **Request:** `DELETE /api/v1/account?confirm={login}`
- **Authentication:** S
- **Success:** 202, no body.
- **Errors:** 422 `invalid_input` when `confirm` does not equal `user.login`.

Right away, in the same request:

- the server revokes all tokens;
- it invalidates the session, and the response clears the cookie;
- it marks the tenant as being deleted, so all its IDs get 404.

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
  "hubUrl": "https://hr.example/.well-known/mercure",
  "topics": ["https://hr.example/tenants/01K6AZZ0TENANT000000000001/repositories","https://hr.example/tenants/01K6AZZ0TENANT000000000001/reviews/{reviewId}"],
  "expiresAt": "2026-09-29T08:31:12Z"
}
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

- **GitHub candidates** come from the list per tenant that the server takes at the last sign-in. It calls `GET /user/installations` and `GET /user/installations/{id}/repositories` with the user token, which it never stores. The `installation_repositories.added` webhook updates the list for installations on the own account of the user. To refresh the list, the user signs in again through `/connect/github`. The setup URL `/github/installed` does that automatically.
- **Local candidates** are scanned again on every call. The scan lists the direct children of `APP_LOCAL_REPOSITORIES_DIR`, skips names that start with `.`, and keeps directories that contain `.git` or are bare repositories.
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
  "githubConfigureUrl": "https://github.com/apps/holistic-review/installations/new"
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

A new repository starts with `sync.state = cloning`, and the server queues a clone job.

Example response, [fixtures/repository.github-cloning.json](fixtures/repository.github-cloning.json):

```json
{
  "id": "01K6B2Z0R3P0S1T2V3W4X5Y6Z7",
  "source": "github",
  "name": "acme/billing",
  "github": {"repositoryId":812345,"installationId":5551,"owner":"acme","private":true,"htmlUrl":"https://github.com/acme/billing"},
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

The endpoint queues a fetch. If `sync.state` is `failed`, it queues the clone again. A fetch that is already queued or running is reused. When the fetch finishes, the server publishes a `repository` live update. The "Refresh" button of the UI calls this endpoint.

### R6 Delete repository data

- **Request:** `DELETE /api/v1/repositories/{repositoryId}?confirm={name}`
- **Authentication:** S
- **Success:** 202, no body.
- **Errors:** 422 `invalid_input` when `confirm` does not equal `name`.

Right away, in the request:

- the repository and its reviews get 404;
- the server revokes the tokens scoped to the repository;
- it publishes `repository_deleted`.

Then a Messenger job deletes the clone with its pin refs, the index, the reviews, the requests, the events, the revoked tokens scoped to the repository, and the repository. The directory or GitHub repository can then be connected again. The GitHub App installation does not change.

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

## 5. Reviews and code

### V1 Start a review

- **Request:** `POST /api/v1/repositories/{repositoryId}/reviews`
- **Authentication:** S
- **Body:** [`StartReviewInput`](schemas/inputs.schema.json), for example `{"ref":"main","title":null}`.
  - `ref` defaults to the default branch.
  - `title` defaults to `"<ref> @ <sha 12 chars>"`, or to `"<sha 12 chars>"` when `ref` is a SHA.
- **Success:** 201 [`Review`](schemas/review.schema.json) with `Location: /api/v1/reviews/{id}`.
- **Errors:**
  - 409 `repository_unavailable`: `cloning`, `clone_failed`, `fetch_failed`, or `removed`;
  - 422 `commit_not_found`: the ref cannot be resolved after the fetch;
  - 422 `invalid_input`: an ambiguous SHA prefix or a malformed ref.

Ref validation: the git ref-name rules of `git check-ref-format --allow-onelevel`, or a hex SHA of 7 to 64 characters. A leading `-` is refused.

Steps:

1. If `ref` is a full SHA that is already in the clone, no fetch runs. Otherwise the server fetches synchronously, with a 60-second timeout.
2. It resolves `git rev-parse --verify --end-of-options <ref>^{commit}` in the clone.
3. It writes the pin ref `refs/holistic-review/reviews/<reviewId>` to the resolved commit.
4. It writes `review.started` with actor user and the payload `title`, `ref`, `commitSha`.
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

- Every write on the review or its requests gets 409 `review_closed`. That covers commands, comments, new requests, viewed files, and the draft operations create, update, and submit.
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
- **Errors:** 409 `repository_unavailable`.

| Query | Type | Default | Notes |
|---|---|---|---|
| `dir` | RepoPath or `""` | `""`, the root | Must name a directory at the review commit. A file gets 422. |
| `recursive` | boolean | false | false: the direct entries, directories first, then byte order of the name. true: every file, symlink, and submodule under `dir`, by path in byte order, without directory entries. |
| `limit` | integer 1-5000 | 1000 | |
| `cursor` | Cursor | none | |

The server reads `git ls-tree` at the pinned commit.

Example response, [fixtures/lists.tree-listing.json](fixtures/lists.tree-listing.json):

```json
{
  "reviewId": "01K6B3A1B2C3D4E5F6G7H8J9KM",
  "commitSha": "79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091",
  "dir": "src/Billing",
  "recursive": false,
  "items": [
    {"path":"src/Billing/Tax","name":"Tax","type":"directory","mode":"040000","sha":"4b825dc642cb6eb9a060e54bf8d69288fbee4904","sizeBytes":null},
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
- `renderable = type == file && !binary && !tooLarge`.
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
- The endpoint serves any size. The 1 MB limit applies only to rendering.

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
- **Success:** 200 [`SymbolList`](schemas/lists.schema.json), or 304.
- **Errors:** as [V7](#v7-get-file-metadata).

Rules:

- The server parses PHP files, `*.php`. It reads the symbols from the `symbol` table by blob SHA. If the blob is not indexed yet, the server parses it synchronously and stores the result. So this endpoint never waits for the index job.
- Other files return `language: null` and `items: []`.
- A parse error returns `parseError: true` and `items: []`.
- `range` includes the doc comment. See decision D7 in [architecture.md](../architecture.md).
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
- **Body:** [`ViewedFileInput`](schemas/inputs.schema.json), `{"path":"src/Billing/InvoiceTotals.php","viewed":true}`.
- **Success:** 200 [`ViewedFiles`](schemas/lists.schema.json)
- **Errors:**
  - 422 `invalid_input`: the path is not a file at the review commit;
  - 409 `review_closed`.

The call is idempotent per path.

### V13 Delete a review

- **Request:** `DELETE /api/v1/reviews/{reviewId}`
- **Authentication:** S
- **Success:** 204, no body.
- **Errors:** common errors only.

The endpoint works for open and closed reviews, and for reviews of a repository whose access was removed. The UI asks for a confirmation in a dialog first.

- One transaction deletes the review and all its rows: requests, comments, commit links, events, the location cache entries, the drafts of every user, and the viewed-file marks.
- After the commit, the server deletes `refs/holistic-review/reviews/<reviewId>` and each `refs/holistic-review/commits/<sha>` that no remaining link in the repository uses, when the clone exists.
- The index stays, because it belongs to the repository and the commit, not to the review.
- The server publishes a `repository` live update, because `openReviewCount` can change.
- Afterwards the review ID and the IDs of its requests and drafts get 404.
