# Slopmower API specification v1

This directory is the v1 contract of Slopmower: the REST API, the MCP server, the GitHub webhooks, the live updates, and the JSON Schemas that all of them share. REST responses, MCP `structuredContent`, the PHP contract tests, and the TypeScript types of the web UI use the same schema files. [architecture.md](../architecture.md) gives the overview and the decisions.

## 1. Files

| File | Content |
|---|---|
| [README.md](README.md) | Conventions, authentication, errors, pagination, caching, repository sources, versioning, schema and fixture index |
| [rest-api.md](rest-api.md) | REST endpoint index, account, repositories, reviews and code |
| [rest-api-requests.md](rest-api-requests.md) | REST requests, updates, drafts, API tokens, and the routes outside `/api/v1` |
| [mcp.md](mcp.md) | MCP endpoint, server instructions, the 18 tools, error mapping, parity with REST |
| [webhooks-and-live-updates.md](webhooks-and-live-updates.md) | GitHub webhooks, fetch triggers and post-fetch steps, Mercure live updates |
| [schemas/](schemas/) | 33 JSON Schema files, draft 2020-12. See [section 10](#10-schemas). |
| [fixtures/](fixtures/) | One realistic example per response shape, error code, live-update type, and request body. See [section 11](#11-fixtures). |

## 2. Conventions

### 2.1 Transport and formats

| Topic | Rule |
|---|---|
| Base path | `/api/v1`. Hosted runs use HTTPS only, through the Caddy server in FrankenPHP. The local development environment serves `http://localhost:8000`. |
| Request body | `Content-Type: application/json`, UTF-8, at most 1 MiB. Other content types get 415 `invalid_input`. Larger bodies get 413 `invalid_input`. |
| Response body | `application/json; charset=utf-8`. The raw and blob endpoints return bytes. Every response under `/api/v1` with a status other than 2xx or 3xx is an [Error](schemas/error.schema.json). That includes unknown routes, which get 404 `not_found`, and wrong methods, which get 405 `invalid_input` with an `Allow` header. |
| IDs | ULID, 26 characters of Crockford base32. Output is upper case. Input is case-insensitive. |
| SHAs | Lower-case hex, 40 characters for SHA-1 or 64 characters for SHA-256 |
| Times | RFC 3339 in UTC with `Z`, second precision: `2026-09-29T07:31:12Z` |
| Nulls | Responses contain every property of their schema. An empty value is `null` or `[]`, never left out. A map is never serialized as `[]`. |
| Unknown input | Unknown body properties and query parameters are ignored. Optional body properties may be left out or sent as `null`. |
| Query values | Booleans: `true`, `false`, `1`, `0`. Lists: comma-separated, for example `status=open,reopened`. |
| CORS | None. The browser UI is same-origin. Bearer clients are not browsers. |

### 2.2 Text limits

| Field | Limit |
|---|---|
| `body`, required `note`, proposal `plan` | 1 to 65,536 characters, at least one non-whitespace character |
| proposal `diff` | 1 to 65,536 characters, a unified diff in git format with 1 to 9 lines that start with `diff --git ` |
| optional `note`, `summary`, close `note` | 0 to 65,536 characters, or null |
| `reason` of won't do | at least 20 characters after trimming, at most 65,536 characters |
| review `title` | 1 to 200 characters |
| request `title` | 1 to 200 characters after trimming, one line without CR or LF, at least one non-whitespace character. The server stores it trimmed. |
| `ref` | 1 to 255 characters |
| pull request `url` | `https`, at most 2,048 characters, no whitespace. For a GitHub repository, `https://github.com/{owner}/{name}/pull/{number}` of the review's repository, compared case-insensitively. The server stores such a URL with the repository name as connected. A local repository accepts any such URL as written. |
| token `name` | 1 to 64 characters, pattern in [api-token.schema.json](schemas/api-token.schema.json) |
| pending suggestions | At most 500 per review. One more gets 422 `invalid_input`. |

## 3. Authentication, actors, and scope

| Mode | Credential | Actor | CSRF | Used by |
|---|---|---|---|---|
| Session (`S`) | Session cookie from the GitHub sign-in at `/login/check-github` through HWIOAuthBundle, or from the local sign-in when `APP_ENV=dev` and `APP_LOCAL_SIGN_IN` holds a login | `user` | Required for POST, PUT, PATCH, DELETE | Web UI |
| Bearer (`B`) | `Authorization: Bearer sm_<43 base64url characters>` | `agent`: `userId` is the token owner, `agentName` is the token name | Not checked | Agents without MCP |

- **Firewalls.** The stateless firewall `api_bearer` comes first. A request matcher selects it for `^/api/v1` requests with an `Authorization: Bearer` header. It uses an `access_token` authenticator whose token handler looks up the SHA-256 hex digest of the full token string. The firewalls `api`, for the other `^/api/v1` requests, and `main` both set `context: web`, so `api` shares the security token of `main`. `api` sets `lazy: true`. The MCP endpoint has its own stateless firewall. See [mcp.md](mcp.md#1-endpoint-and-authentication).
- **Precedence and sessions.** When a request has both an `Authorization` header and a session cookie, the bearer token wins, because `api_bearer` matches first. A bearer request never starts a session: the response has no `Set-Cookie`.
- **Token checks.** A token that does not match `^sm_[A-Za-z0-9_-]{43}$` gets 401 without a database lookup. Unknown, revoked, and expired tokens get 401. A token is expired when `expiresAt <= now`. `lastUsedAt` is updated at most once per 60 seconds.
- **401 headers.** Every 401 carries `WWW-Authenticate: Bearer realm="slopmower"`. When the request sent a token, the header adds `, error="invalid_token"`.
- **CSRF.** Session writes must pass `SameOriginCsrfTokenManager` with the stateless token ID `api`. The request needs `Sec-Fetch-Site: same-origin`, an `Origin` or `Referer` header that matches the host, or a double-submit token of at least 24 characters in the `csrf-token` header. An origin header that does not match fails also with the token. A failure gets 403 `forbidden`.
- **Tenant context.** Each request sets a fail-closed tenant context from the session user or from the token. A Doctrine SQL filter limits every ORM query to that tenant. Without a tenant context, no tenant data is read.
- **Visibility.** A caller sees only its own tenant. A token scoped to one repository sees only that repository and its reviews, requests, drafts, and blobs. Any ID outside that visibility gets **404 `not_found`**, in the path, the query, or the body. An unknown name in the MCP `repository` argument, and a connect input that is not a candidate, also get 404. `GET /api/v1/repositories?name=` returns an empty page instead.
- **Actor kinds.** The "Authentication" column of the [endpoint index](rest-api.md#2-endpoint-index) lists the modes that may call each endpoint. A token call to an `S`-only endpoint, or a session call to a `B`-only endpoint, gets 403 `forbidden`.

## 4. Errors

The body is `{"error": {"code", "message", "details"}}`. `message` is English and not stable. `details` is `null` or an object. Its shape depends on the code. [error.schema.json](schemas/error.schema.json) defines each shape.

| Code | HTTP | Meaning | `details` |
|---|---|---|---|
| `unauthorized` | 401 | No credential, or the credential is wrong, revoked, or expired | null |
| `forbidden` | 403 | Authenticated, but this actor kind may not call this endpoint, or the CSRF check failed | null |
| `not_found` | 404 | Unknown route, ID, or name. Also another tenant's ID, a repository outside the token's scope, a commit that is not linked to the request, and a blob that is not in the clone. | null |
| `invalid_input` | 400, 405, 413, 415, 422 | 400: unparsable JSON or a query value of the wrong type. 405: wrong method. 413: body too large. 415: not JSON. 422: validation or domain rule failed. | `{violations: [{field, message}]}` for 400 and 422. Otherwise null. |
| `invalid_transition` | 409 | The status of the request, the claim holder, or the proposal state does not allow the command | `{requestId, action, status, claimedBy, allowedActions}` |
| `review_closed` | 409 | A write on a closed review or on one of its requests | `{reviewId, closedAt}` |
| `commits_not_available` | 409 | `mark_ready_for_review` while a linked commit is still `pending` after one fetch, or a commit diff of a pending link | `{pendingCommits: [sha]}` |
| `commit_not_found` | 422 | A ref or commit that the caller named, such as `at` or the review `ref`, is not in the server clone after one fetch. An endpoint that does not fetch, such as [R9](rest-api.md#r9-list-the-latest-commits), checks the clone as it is. | `{commit}` |
| `repository_unavailable` | 409 | The operation needs the clone or a fetch, and the repository is cloning, its clone failed, its fetch failed, or its access was removed | `{repositoryId, reason: cloning\|clone_failed\|fetch_failed\|removed}` |
| `rate_limited` | 429 | A rate limit refused the call. See [Rate limits](#rate-limits). | `{retryAfterSeconds, limit}` |
| `limit_exceeded` | 409 | The call would pass a usage limit of the tenant. See [Usage limits](#usage-limits). | `{limit, max, current}` |
| `internal_error` | 500 | An unexpected failure. The server logs it with the exception. The message is generic. | null |

Check order:

1. 401 `unauthorized`.
2. 429 `rate_limited` of the limiters per call.
3. 404 `not_found`: existence and visibility.
4. 403 `forbidden`: actor kind or CSRF.
5. 400 or 422 `invalid_input`.
6. 409 `review_closed`.
7. 409 `invalid_transition`.
8. `repository_unavailable`, `commit_not_found`, and `commits_not_available`. The limiters `review_start` and `sync_fetch` answer 429 `rate_limited` at the step that they protect.
9. 409 `limit_exceeded`.

If an ID is not visible, the caller gets 404 before any 403.

Two exceptions follow from the Symfony request cycle:

- The router runs before the firewall. An unknown route gets 404, and a wrong method gets 405, also without a credential.
- IDs in the request body are checked after the body validation. An invalid body gets 400 or 422 before a 404 for a body ID.

Fixtures: one file per code, `fixtures/error.<code>.json` with `-` in place of `_`. See [section 11](#11-fixtures).

### Rate limits

Each call consumes one token of every limiter that applies to it. The limits are values of the instance, not of a plan. [Deployment](../deployment/README.md#usage-and-rate-limits) lists their variables.

| Limiter | Counts | Key | Default |
|---|---|---|---|
| `api_token` | Each call under `/api/v1` with a token, and each MCP `tools/call` | Token | 300 per minute, token bucket |
| `api_user` | Each call under `/api/v1`, and each MCP `tools/call`: the session and all tokens of the user together | User | 1,200 per minute, token bucket |
| `mcp_http` | Each HTTP request to `/_mcp` | Token | 600 per minute, token bucket |
| `sync_fetch` | Each synchronous fetch: [V1](rest-api.md#v1-start-a-review), [Q3](rest-api-requests.md#q3-get-a-request) with `at`, and [Q10](rest-api-requests.md#q10-mark-ready-for-review) | Repository | 10 per minute, token bucket |
| `review_start` | Each [V1](rest-api.md#v1-start-a-review) | Tenant | 30 per hour, token bucket |

- REST: a refused call gets 429 `rate_limited` with `Retry-After` in seconds, `X-RateLimit-Limit`, and `X-RateLimit-Remaining`. `details.retryAfterSeconds` equals `Retry-After`, and `details.limit` equals `X-RateLimit-Limit`.
- MCP: a refused `tools/call` is an `isError` result with `rate_limited`. `mcp_http` is the last line of defence: a refused HTTP request gets HTTP 429 with the same headers and Error body, before JSON-RPC.
- `/webhook/github` has no rate limit. Its signature check protects it.

### Usage limits

The [plan of the tenant](webhooks-and-live-updates.md#marketplace-plans) sets each usage limit. A tenant without a GitHub Marketplace plan has the free plan. The instance sets the values of each plan, and the table gives the defaults of the free plan. A limit with the value 0 is off.

| `limit` | Use, `current` | Free plan | Checked at |
|---|---|---|---|
| `repositories` | Connected repositories of the tenant | 25 | [R3](rest-api.md#r3-connect-a-repository) of a new repository |
| `open_reviews` | Open reviews of the tenant | 20 | [V1](rest-api.md#v1-start-a-review) |
| `requests_per_review` | Requests of the review | 1,000 | [Q2](rest-api-requests.md#q2-open-a-request), [Q20](rest-api-requests.md#q20-suggest-a-request), [D5](rest-api-requests.md#d5-submit-drafts) for its `new_request` drafts, and [V15](rest-api.md#v15-carry-over-open-requests) for the carried requests |
| `repository_size` | Bytes of one clone: `size` plus `size-pack` of `git count-objects -v`, because git keeps a fetch of fewer than 100 objects loose | 2 GiB | Clone and fetch |
| `tenant_storage` | Bytes of all clones of the tenant | 10 GiB | Clone and fetch |

- A call that would pass a limit gets 409 `limit_exceeded`. `details.current` is the use before the call, and `details.max` is the limit. Sizes are in bytes.
- A clone above a size limit fails: `sync.state` is `failed`, `sync.lastError` names the limit, and the server deletes the clone. For a GitHub repository, the server also checks the `size` of the repository in the GitHub API before the clone.
- A fetch that passes a size limit keeps the clone and sets `sync.lastError`. While the clone or the tenant is above a size limit, each fetch of the repository fails, so an operation that needs a fetch gets `repository_unavailable` with reason `fetch_failed`.
- [A1](rest-api.md#a1-get-the-caller) returns the use and the limits in `usage`.

## 5. Pagination

- Lists that can grow take `limit` and `cursor` and return `nextCursor`. `nextCursor` is null on the last page.
- A cursor is opaque: base64url JSON of the sort key of the last item, plus a hash of the filters. It never expires. A cursor sent with other filters gets 422 `invalid_input`.
- Bounded lists are not paginated: drafts, tokens, viewed files, candidates, symbols, declarations, search hits, and commits. Candidates stop at 1,000 items and set `truncated`.
- `GET /api/v1/reviews/{reviewId}/updates` uses a different cursor: the integer event number. See [rest-api-requests.md](rest-api-requests.md#4-updates).

## 6. Caching and ETags

| Endpoint | ETag | Cache-Control | 304 |
|---|---|---|---|
| `GET /reviews/{id}/raw`, `GET /repositories/{id}/blobs/{sha}` | `"<blobSha>"`, strong | `private, max-age=31536000, immutable` | When `If-None-Match` matches with the weak comparison, or is `*` |
| `GET /reviews/{id}/file` | `W/"v2-<blobSha>"`. Increase `v2` when the representation changes, also for a new `language` value of a file name. | `private, no-cache` | Same rule |
| `GET /reviews/{id}/symbols` | `W/"v5-<blobSha>"`. Increase `v5` when the representation changes, also for a changed grammar or query of a language. | `private, no-cache` | Same rule |
| `GET /reviews/{id}/blame` | `W/"v1-<commitSha>-<blobSha>"`, from the review commit and the file. Increase `v1` when the representation changes. | `private, no-cache` | Same rule |
| Everything else | none | `no-store` | never |

All cacheable responses send `Vary: Authorization, Cookie`. A 304 has no body and repeats `ETag` and `Cache-Control`.

## 7. Repository sources, names, and commit availability

| Source | `name` | Identity | Connect input |
|---|---|---|---|
| `github` | `owner/name` as on GitHub. The `repository.renamed` webhook and each sign-in update it. | `github.repositoryId` | `githubRepositoryId` from the candidates |
| `local` | The name of a **direct child directory** of `APP_LOCAL_REPOSITORIES_DIR`: no `/`, no leading `.` | `local.directory` | `directory` from the candidates |

- **Local repositories are an instance feature.** When `APP_LOCAL_REPOSITORIES_DIR` is set, every tenant of the instance may list and connect the repositories under it. The feature is meant for local and self-hosted runs. When the variable is unset or empty, the feature is off.
- **Resolving a `repository` argument** in MCP tools and in `GET /api/v1/repositories?name=`:
  1. A valid ULID is a repository ID.
  2. A value that contains `/` is a GitHub name, compared case-insensitively.
  3. Any other value is a local directory name, compared exactly.
- **Uniqueness.** Names are unique per tenant: GitHub names always contain `/`, local names never do. A second connect of the same source identity returns the existing repository.
- **Local directories.** The server resolves the directory with `realpath()`. Its parent must equal `realpath(APP_LOCAL_REPOSITORIES_DIR)`, so symlinks cannot escape the mount. For a local source, the `git upload-pack` command that reads the source runs with `-c safe.directory=<realpath>` and `-c safe.directory=<realpath>/.git`. The server never writes to the source directory.
- **Server clone.** Every repository has one bare clone at `$APP_TENANT_DATA_DIR/<tenantId>/repositories/<repositoryId>.git`. Two tenants that connect the same source get two clones.
- **Fetch.** A fetch from the source takes `+refs/heads/*:refs/heads/*` and `+refs/tags/*:refs/tags/*` with `--prune`, never `--mirror`. A second `git fetch` then gets the pending linked SHAs by ID into `refs/slopmower/commits/<sha>`. One unknown SHA fails a whole `git fetch`, so when the second fetch fails, the server fetches one SHA per command and ignores the failures.
- **Pin refs.** At review start the server writes `refs/slopmower/reviews/<reviewId>` to the review commit. When a linked commit is present in the clone, the server writes `refs/slopmower/commits/<sha>`. The fetch refspecs never touch the `refs/slopmower/` namespace, so force-pushes, pruning, and `git gc` cannot remove reviewed or linked commits. Deleting a review, unlinking the last link of a commit, or deleting the repository data deletes the matching pin refs.
- **Available.** A commit is *available* when `git cat-file -e <sha>^{commit}` succeeds in the clone. For a local source, a local commit becomes available at the next fetch, without a push. For a GitHub source, the commit must be pushed.
- **When fetches run.** See [webhooks-and-live-updates.md](webhooks-and-live-updates.md#2-fetches-and-commit-availability).

## 8. Versioning and compatibility

1. **Version identifiers.** REST uses `/api/v1`. MCP is one endpoint, `/_mcp`. Its contract version is the major number of `serverInfo.version`, which is `1.x.y`. Schemas are served at `/docs/schemas/v1/`. One v1 contract covers REST, MCP, the schemas, the live-update payloads, and `SKILL.md`.
2. **Allowed within v1:**
   - new endpoints and tools;
   - new optional input fields or query parameters whose absence keeps the old behaviour;
   - new response properties;
   - new values in **open** sets: `Action`, `EventType`, `ThreadEntryType`, `ErrorCode`, `SymbolKind`, `UsageLimit`, `language`, the live-update `type`, and the search hit `kind`;
   - new error codes for new situations;
   - relaxed validation, for example higher limits;
   - new headers.
3. **Breaking changes, which need v2:**
   - removing or renaming an endpoint, tool, parameter, or property;
   - changing a type, format, or nullability. Making a non-null field nullable is breaking.
   - a new required input;
   - tighter validation;
   - new values in **closed** sets: `RequestStatus`, `Awaiting`, `Resolution`, `AnchorKind`, `ActorKind`, `CommitLinkState`, `LocationStatus`, `OrphanReason`, `ProposalState`, `PullRequestState`, `SuggestionState`, `RepositorySource`, `RepositoryAccess`, `ReviewState`, `IndexState`, `CarryOverOutcome`, `sync.state`, token `scope` and `state`, commit file `status`, tree entry `type`;
   - a different HTTP status or error code for an existing situation;
   - changed defaults, sort orders, or pagination style;
   - a changed meaning of an existing field.
4. **Clients must:**
   - ignore unknown properties;
   - show unknown open-set values generically;
   - treat unknown error codes by HTTP status class, and in MCP as a generic failure;
   - not parse `message`;
   - pass cursors back unchanged;
   - follow `nextCursor` and `hasMore`.
5. **The server must:**
   - emit every schema property;
   - never add `additionalProperties: false` to response schemas;
   - validate the responses in tests: REST responses against the files in [schemas/](schemas/), and MCP `structuredContent` against the `outputSchema` of each tool, both with `opis/json-schema` 2.6.0.
6. **Deprecation:**
   - The `/docs` changelog announces it.
   - Deprecated REST endpoints send `Deprecation: @<unix time>` as in RFC 9745, `Sunset: <HTTP-date>` as in RFC 8594, and `Link: <https://<host>/docs/<page>>; rel="deprecation"`.
   - The description of a deprecated MCP tool starts with `Deprecated: use <tool>.`
   - A v2 runs next to v1 for at least 90 days.
7. **SKILL.md** carries `metadata: {api: "v1"}` in its frontmatter and names only v1 tools. Its source is `docs/user/skill/SKILL.md`, and `GET /docs/skill/SKILL.md` serves it raw. The PHPUnit skill test fails when the skill names a tool that does not exist.
8. **Stored events** are never rewritten. New payload fields are additive, and readers use a default for a missing field. The API `Event` is a derived representation, not the stored payload.
9. **MCP protocol revisions**, 2025-11-25 with the handshake and 2026-07-28 stateless, are independent of the API version. Every output schema keeps an object root, which the handshake era requires.
10. **Amendments before the first release.** Until the first release, v1 is amended in place, without a v2, because its only clients are the web UI and the bundled skill. The amendments:
    - General requests, decision D45 of [architecture.md](../architecture.md) section 11: `anchor` is nullable in `Request`, `RequestSummary`, and `Draft`, and optional in `OpenRequestInput` and `CreateDraftInput`.
    - Agent proposals, decision D49: `link_commits` and `mark_ready_for_review` are refused while a proposal is pending or rejected, and while `requireApproval` holds without an approved proposal. `awaiting` rule 2 counts a proposal decision as a user message, and rule 3 also matches a pending proposal. `request.opened` has the payload field `requireApproval`, read as false when missing. New: the actions `propose_solution`, `approve_proposal`, and `reject_proposal`, three event and thread entry types, the closed set `ProposalState` on the new property `proposal`, and `diff` in `Event` and `ThreadEntry`.
    - Pull request links, decision D50: the endpoint Q17 and the tool `link_pull_request`, the `Action` value `link_pull_request`, the `EventType` and `ThreadEntryType` value `pull_request.linked`, `Request.pullRequestUrls`, and `pullRequestUrl` in `ThreadEntry` and `Event`. All are additive. On an `in_progress` request, the gate of D49 applies to `link_pull_request` as to `link_commits`.
    - Q9 file order, decision D51: `files` of `CommitDiff` lists the files that touch the anchor first, then the other files in the order of `git diff-tree`. It is the order that the server returns, so no client sees a change.
    - Plan requirement changes, decision D52: the endpoint Q18, the `Action` value `set_plan_requirement`, the `EventType` and `ThreadEntryType` values `request.plan_required` and `request.plan_waived`, and the input `PlanRequirementInput`. All are additive. `requireApproval` changes after the request opens, and the gate of D49 uses its current value.
    - Pull request unlinks, decision D53: the endpoint Q19 and the tool `unlink_pull_request`, the `Action` value `unlink_pull_request`, the `EventType` and `ThreadEntryType` value `pull_request.unlinked`, and `UnlinkPullRequestInput`. All are additive.
    - Blame, decision D59: the endpoint V17 and the schema `Blame`. All are additive.
    - Quick open ranking, decision D54: V16 also matches a name by word starts, for example `CE` for `CommandExecutor`, ranks word starts between a name prefix and a name substring, and orders by the length of the name before the length of the path. The wire format does not change.
    - Symbols for more languages, decisions D56 and D57: V9, symbol anchors, and V16 also cover JavaScript, JSX, TypeScript, TSX, Python, Go, Rust, Java, C#, Ruby, Bash, Scala, Zig, and Lua files. `language` takes their V7 values, the open set `SymbolKind` gets `struct`, `type`, `module`, and `macro`, and the V9 ETag has its own version `v4`. V7 gives `scala`, `zig`, and `lua` for `.scala`, `.zig`, and `.lua` files, and its ETag is `v2`. V14 stays PHP only.
    - Twig symbols, decision D60: V9, symbol anchors, and V16 also cover Twig files. `language` is `twig`, the open set `SymbolKind` gets `block`, and the V9 ETag is `v5`.
    - Pull request state, decision D64: `Request.pullRequests` with the closed set `PullRequestState`, and the live update type `pull_request`. All are additive. For a GitHub repository, `link_pull_request` and `unlink_pull_request` match a linked pull request by its number, so a link under an earlier name of the repository still matches after a rename, and `unlink_pull_request` also takes the URL as linked. The server reads the state of a linked GitHub pull request.
    - Check of every pull request, decision D64: the endpoint R8, the input `CheckEveryPullRequestInput`, and `github.checkEveryPullRequest` in `Repository`. All are additive.
    - Product name, decision D14: the MCP server name, `serverInfo.name`, the skill name, the Bearer realm, the pin refs, the live update ID, and the schema `$id` base use `slopmower`. Token secrets start with `sm_`.
    - Agent suggestions, decision D72: the endpoints Q20, Q21, and Q22, the tools `start_review` and `suggest_request`, the `Action` values `accept_suggestion` and `dismiss_suggestion`, the `EventType` and `ThreadEntryType` values `request.suggestion_accepted` and `request.suggestion_dismissed`, the inputs `SuggestRequestInput`, `AcceptSuggestionInput`, and `DismissSuggestionInput`, the closed set `SuggestionState` on the new property `suggestion`, and the filter `suggestion` of Q1 and `list_requests`. All are additive. V1 also accepts `B`. A `request.opened` with an agent actor opens a suggestion with the same payload, and one with a user actor gives `suggestion: null`, so stored events need no change. While a suggestion is pending, `claim`, `mark_wont_do`, and `set_plan_requirement` are refused, `awaiting` rule 3 matches it, and rule 2 counts an accept as a user message. V15 does not carry a pending suggestion.
    - Request titles, decision D75: the endpoint Q23 and the tool `set_request_title`, the `Action` value `set_title`, the `EventType` and `ThreadEntryType` value `request.retitled`, the input `TitleInput`, the property `title` of `Request`, `RequestSummary`, and `Draft`, and the optional input `title` of Q2, D2, and D3. These are additive. Not additive: the required `title` of Q20 and `suggest_request`, and the new meaning of `summary`, which is the title when one is set. `request.opened` has the payload field `title`, read as null when missing, so stored events need no change.
    - Plain-text summary, decision D80: without a title, `summary` is the plain text of the first non-blank line of the body, without Markdown syntax. It is a changed meaning of an existing field. The wire format does not change.
    - Latest commits, decision D76: the endpoint R9 and the schemas `Commit` and `CommitLog`. All are additive.

## 9. Contract tests

- Functional tests validate REST responses with `JsonApiAssertions::assertApiResponse()`, which uses `opis/json-schema` 2.6.0.
- Every MCP `structuredContent` is validated against the `outputSchema` of its tool.
- Every fixture in [section 11](#11-fixtures) is validated against the schema that the table names. The TypeScript types of the web UI are checked against the same fixture files.
- `McpRestParityTest` compares the MCP and REST payloads of every tool: `list_repositories`, `list_reviews`, `list_requests`, `get_updates`, `get_request`, and every write tool. See [mcp.md](mcp.md#6-mcp-and-rest).

## 10. Schemas

- Every file uses `"$schema": "https://json-schema.org/draft/2020-12/schema"` and a 2-space indent.
- `$id` has the base `https://slopmower.invalid/schemas/v1/`. The `.invalid` top-level domain of RFC 2606 makes it a stable identifier that never resolves.
- The tests register an `opis/json-schema` loader that maps this prefix to `docs/specs/schemas/`: `$validator->resolver()->registerPrefix('https://slopmower.invalid/schemas/v1/', $schemaDir)`.
- `GET /docs/schemas/v1/{file}.schema.json` serves the same files as `application/schema+json`.
- Response schemas never set `additionalProperties: false`.
- Patterns use only syntax that both ECMA-262 and PCRE accept, for example `\x00` and not `\u0000`.

| File | `$id` | Content |
|---|---|---|
| [common.schema.json](schemas/common.schema.json) | `https://slopmower.invalid/schemas/v1/common.schema.json` | `$defs` only: IDs, SHAs, paths, times, cursors, `Markdown`, `Title`, `UnifiedDiff`, `Proposal`, and the shared enums |
| [error.schema.json](schemas/error.schema.json) | `https://slopmower.invalid/schemas/v1/error.schema.json` | Error body and the `details` shape per code |
| [actor.schema.json](schemas/actor.schema.json) | `https://slopmower.invalid/schemas/v1/actor.schema.json` | Actor: user, agent, or system |
| [anchor.schema.json](schemas/anchor.schema.json) | `https://slopmower.invalid/schemas/v1/anchor.schema.json` | Anchor of a request with a code location, plus `LineRange`, `SymbolRef`, `ContentSnapshot`, `AnchorSummary`, `AnchorInput`. A general request has no anchor. |
| [location.schema.json](schemas/location.schema.json) | `https://slopmower.invalid/schemas/v1/location.schema.json` | Location of an anchor at another commit |
| [repository.schema.json](schemas/repository.schema.json) | `https://slopmower.invalid/schemas/v1/repository.schema.json` | Repository, plus `LocalDirectory` and `Sync` |
| [repository-candidate.schema.json](schemas/repository-candidate.schema.json) | `https://slopmower.invalid/schemas/v1/repository-candidate.schema.json` | A repository that the user can connect |
| [review.schema.json](schemas/review.schema.json) | `https://slopmower.invalid/schemas/v1/review.schema.json` | Review, plus `IndexStatus` and `RequestCounts` |
| [request-summary.schema.json](schemas/request-summary.schema.json) | `https://slopmower.invalid/schemas/v1/request-summary.schema.json` | RequestSummary for lists and command results, plus `CarriedFrom`. `anchor` is null for a general request, `title` is null for a request without a title, and `suggestion` is null for a request of the user. |
| [request.schema.json](schemas/request.schema.json) | `https://slopmower.invalid/schemas/v1/request.schema.json` | Request in full, plus `PullRequest` and `CodeExcerpt`. A general request has `anchor`, `location`, `excerpt`, and `outdated` null. A request without a title has `title` null. A request of the user has `suggestion` null. |
| [thread-entry.schema.json](schemas/thread-entry.schema.json) | `https://slopmower.invalid/schemas/v1/thread-entry.schema.json` | One entry of a request thread |
| [event.schema.json](schemas/event.schema.json) | `https://slopmower.invalid/schemas/v1/event.schema.json` | One event of the review event log |
| [commit-link.schema.json](schemas/commit-link.schema.json) | `https://slopmower.invalid/schemas/v1/commit-link.schema.json` | A commit linked to a request |
| [commit-diff.schema.json](schemas/commit-diff.schema.json) | `https://slopmower.invalid/schemas/v1/commit-diff.schema.json` | Diff data of one linked commit, plus `CommitDiffFile` |
| [commit.schema.json](schemas/commit.schema.json) | `https://slopmower.invalid/schemas/v1/commit.schema.json` | One commit of a commit list |
| [tree-entry.schema.json](schemas/tree-entry.schema.json) | `https://slopmower.invalid/schemas/v1/tree-entry.schema.json` | One entry of a tree listing |
| [file-content.schema.json](schemas/file-content.schema.json) | `https://slopmower.invalid/schemas/v1/file-content.schema.json` | File metadata at the review commit |
| [blame.schema.json](schemas/blame.schema.json) | `https://slopmower.invalid/schemas/v1/blame.schema.json` | The blame of a file at the review commit, plus `BlameRange` and `BlameCommit` |
| [symbol.schema.json](schemas/symbol.schema.json) | `https://slopmower.invalid/schemas/v1/symbol.schema.json` | One symbol of a file |
| [declaration.schema.json](schemas/declaration.schema.json) | `https://slopmower.invalid/schemas/v1/declaration.schema.json` | One declaration of a name at the review commit |
| [search-hit.schema.json](schemas/search-hit.schema.json) | `https://slopmower.invalid/schemas/v1/search-hit.schema.json` | One hit of a search at the review commit: `FileHit` or `SymbolHit` |
| [draft.schema.json](schemas/draft.schema.json) | `https://slopmower.invalid/schemas/v1/draft.schema.json` | A draft of the pending batch |
| [batch-submission.schema.json](schemas/batch-submission.schema.json) | `https://slopmower.invalid/schemas/v1/batch-submission.schema.json` | Result of a batch submission |
| [carry-over-result.schema.json](schemas/carry-over-result.schema.json) | `https://slopmower.invalid/schemas/v1/carry-over-result.schema.json` | Result of a carry-over, plus `CarryOverItem` and `CarryOverOutcome` |
| [api-token.schema.json](schemas/api-token.schema.json) | `https://slopmower.invalid/schemas/v1/api-token.schema.json` | ApiToken, plus `TokenName` and `ApiTokenWithSecret` |
| [updates-page.schema.json](schemas/updates-page.schema.json) | `https://slopmower.invalid/schemas/v1/updates-page.schema.json` | Result of the updates endpoint and `get_updates`, plus `UpdatedRequest` |
| [request-command-result.schema.json](schemas/request-command-result.schema.json) | `https://slopmower.invalid/schemas/v1/request-command-result.schema.json` | Result of a request command |
| [link-commits-result.schema.json](schemas/link-commits-result.schema.json) | `https://slopmower.invalid/schemas/v1/link-commits-result.schema.json` | Result of `link_commits` |
| [link-pull-request-result.schema.json](schemas/link-pull-request-result.schema.json) | `https://slopmower.invalid/schemas/v1/link-pull-request-result.schema.json` | Result of `link_pull_request` |
| [me.schema.json](schemas/me.schema.json) | `https://slopmower.invalid/schemas/v1/me.schema.json` | The caller, the tenant, the features, the plan, the usage, and the links, plus `TenantPlan` and `LimitUsage` |
| [lists.schema.json](schemas/lists.schema.json) | `https://slopmower.invalid/schemas/v1/lists.schema.json` | `$defs` only: every page and list, and `MercureAuthorization` |
| [inputs.schema.json](schemas/inputs.schema.json) | `https://slopmower.invalid/schemas/v1/inputs.schema.json` | `$defs` only: every REST request body |
| [live-update.schema.json](schemas/live-update.schema.json) | `https://slopmower.invalid/schemas/v1/live-update.schema.json` | Data of one Mercure update, IDs only |

## 11. Fixtures

- A fixture is a complete, realistic document. Its name is `<schema>.<case>.json`, where `<schema>` is the base name of its schema file.
- Most fixtures tell one story: the user reviews `acme/billing` at commit `79f8595a4c1e`, opens two requests, and the agent `claude-code laptop` works on them and suggests one more. The others show a closed review, other token states, and a local sign-in.
- Every fixture must validate against the schema in the table. Every inline JSON example in these specification files that names a fixture must equal that fixture.

| Fixture | Schema | Used by |
|---|---|---|
| [api-token.with-secret.json](fixtures/api-token.with-secret.json) | `api-token.schema.json#/$defs/ApiTokenWithSecret` | T2 |
| [batch-submission.submitted.json](fixtures/batch-submission.submitted.json) | `batch-submission.schema.json` | D5 |
| [blame.php.json](fixtures/blame.php.json) | `blame.schema.json` | V17 |
| [carry-over-result.mixed.json](fixtures/carry-over-result.mixed.json) | `carry-over-result.schema.json` | V15 |
| [commit-diff.modified.json](fixtures/commit-diff.modified.json) | `commit-diff.schema.json` | Q9 |
| [draft.new-general-request.json](fixtures/draft.new-general-request.json) | `draft.schema.json` | D2 |
| [draft.new-request.json](fixtures/draft.new-request.json) | `draft.schema.json` | D2, D3 |
| [draft.reply.json](fixtures/draft.reply.json) | `draft.schema.json` | D2, D3 |
| [error.commit-not-found.json](fixtures/error.commit-not-found.json) | `error.schema.json` | R9, V1, Q3, `get_request`, `start_review` |
| [error.commits-not-available.json](fixtures/error.commits-not-available.json) | `error.schema.json` | Q9, Q10, `mark_ready_for_review` |
| [error.forbidden.json](fixtures/error.forbidden.json) | `error.schema.json` | Any `S`-only endpoint called with a token |
| [error.internal-error.json](fixtures/error.internal-error.json) | `error.schema.json` | Any endpoint and tool |
| [error.invalid-input-method.json](fixtures/error.invalid-input-method.json) | `error.schema.json` | Any path with a wrong method, 405 |
| [error.invalid-input.json](fixtures/error.invalid-input.json) | `error.schema.json` | Q11, `mark_wont_do` |
| [error.invalid-transition.json](fixtures/error.invalid-transition.json) | `error.schema.json` | Q5, `claim_request` |
| [error.limit-exceeded.json](fixtures/error.limit-exceeded.json) | `error.schema.json` | R3, V1, V15, Q2, Q20, D5 |
| [error.not-found.json](fixtures/error.not-found.json) | `error.schema.json` | Any invisible ID |
| [error.rate-limited.json](fixtures/error.rate-limited.json) | `error.schema.json` | Any endpoint and tool, the MCP transport |
| [error.repository-unavailable.json](fixtures/error.repository-unavailable.json) | `error.schema.json` | R5, R9, V1, V6 to V9, V14, V17, Q2, Q3, Q9, Q10, Q20 |
| [error.review-closed.json](fixtures/error.review-closed.json) | `error.schema.json` | Writes on a closed review |
| [error.unauthorized.json](fixtures/error.unauthorized.json) | `error.schema.json` | Any endpoint, the MCP transport |
| [file-content.php.json](fixtures/file-content.php.json) | `file-content.schema.json` | V7 |
| [inputs.accept-suggestion.json](fixtures/inputs.accept-suggestion.json) | `inputs.schema.json#/$defs/AcceptSuggestionInput` | Q21 |
| [inputs.approve-proposal.json](fixtures/inputs.approve-proposal.json) | `inputs.schema.json#/$defs/ApproveProposalInput` | Q15 |
| [inputs.carry-over.json](fixtures/inputs.carry-over.json) | `inputs.schema.json#/$defs/CarryOverInput` | V15 |
| [inputs.close-review.json](fixtures/inputs.close-review.json) | `inputs.schema.json#/$defs/CloseReviewInput` | V4 |
| [inputs.comment.json](fixtures/inputs.comment.json) | `inputs.schema.json#/$defs/CommentInput` | Q4 |
| [inputs.connect-github.json](fixtures/inputs.connect-github.json) | `inputs.schema.json#/$defs/ConnectRepositoryInput` | R3 |
| [inputs.connect-local.json](fixtures/inputs.connect-local.json) | `inputs.schema.json#/$defs/ConnectRepositoryInput` | R3 |
| [inputs.create-draft-general-request.json](fixtures/inputs.create-draft-general-request.json) | `inputs.schema.json#/$defs/CreateDraftInput` | D2 with a title |
| [inputs.create-draft-new-request.json](fixtures/inputs.create-draft-new-request.json) | `inputs.schema.json#/$defs/CreateDraftInput` | D2 |
| [inputs.create-draft-reply.json](fixtures/inputs.create-draft-reply.json) | `inputs.schema.json#/$defs/CreateDraftInput` | D2 |
| [inputs.create-token.json](fixtures/inputs.create-token.json) | `inputs.schema.json#/$defs/CreateTokenInput` | T2 |
| [inputs.dismiss-suggestion.json](fixtures/inputs.dismiss-suggestion.json) | `inputs.schema.json#/$defs/DismissSuggestionInput` | Q22 |
| [inputs.link-commits.json](fixtures/inputs.link-commits.json) | `inputs.schema.json#/$defs/LinkCommitsInput` | Q7 |
| [inputs.link-pull-request.json](fixtures/inputs.link-pull-request.json) | `inputs.schema.json#/$defs/LinkPullRequestInput` | Q17 |
| [inputs.open-general-request.json](fixtures/inputs.open-general-request.json) | `inputs.schema.json#/$defs/OpenRequestInput` | Q2 with a title |
| [inputs.open-request-approval.json](fixtures/inputs.open-request-approval.json) | `inputs.schema.json#/$defs/OpenRequestInput` | Q2 with "Ask for a plan first" |
| [inputs.open-request.json](fixtures/inputs.open-request.json) | `inputs.schema.json#/$defs/OpenRequestInput` | Q2 |
| [inputs.plan-requirement.json](fixtures/inputs.plan-requirement.json) | `inputs.schema.json#/$defs/PlanRequirementInput` | Q18 |
| [inputs.propose-solution.json](fixtures/inputs.propose-solution.json) | `inputs.schema.json#/$defs/ProposeSolutionInput` | Q14, `propose_solution` |
| [inputs.ready.json](fixtures/inputs.ready.json) | `inputs.schema.json#/$defs/RequiredNoteInput` | Q10, Q13 |
| [inputs.reject-proposal.json](fixtures/inputs.reject-proposal.json) | `inputs.schema.json#/$defs/RejectProposalInput` | Q16 |
| [inputs.release.json](fixtures/inputs.release.json) | `inputs.schema.json#/$defs/OptionalNoteInput` | Q6, Q12 |
| [inputs.start-review.json](fixtures/inputs.start-review.json) | `inputs.schema.json#/$defs/StartReviewInput` | V1 |
| [inputs.submit-drafts.json](fixtures/inputs.submit-drafts.json) | `inputs.schema.json#/$defs/SubmitDraftsInput` | D5 |
| [inputs.suggest-request.json](fixtures/inputs.suggest-request.json) | `inputs.schema.json#/$defs/SuggestRequestInput` | Q20, `suggest_request` |
| [inputs.title.json](fixtures/inputs.title.json) | `inputs.schema.json#/$defs/TitleInput` | Q23, `set_request_title` |
| [inputs.unlink-pull-request.json](fixtures/inputs.unlink-pull-request.json) | `inputs.schema.json#/$defs/UnlinkPullRequestInput` | Q19 |
| [inputs.update-draft.json](fixtures/inputs.update-draft.json) | `inputs.schema.json#/$defs/UpdateDraftInput` | D3 |
| [inputs.viewed-file.json](fixtures/inputs.viewed-file.json) | `inputs.schema.json#/$defs/ViewedFileInput` | V12 |
| [inputs.wont-do.json](fixtures/inputs.wont-do.json) | `inputs.schema.json#/$defs/WontDoInput` | Q11 |
| [link-commits-result.available.json](fixtures/link-commits-result.available.json) | `link-commits-result.schema.json` | Q7, `link_commits` |
| [link-commits-result.pending.json](fixtures/link-commits-result.pending.json) | `link-commits-result.schema.json` | Q7, `link_commits` |
| [link-pull-request-result.linked.json](fixtures/link-pull-request-result.linked.json) | `link-pull-request-result.schema.json` | Q17, `link_pull_request` |
| [lists.candidate-list.json](fixtures/lists.candidate-list.json) | `lists.schema.json#/$defs/CandidateList` | R2 |
| [lists.commit-list.json](fixtures/lists.commit-list.json) | `lists.schema.json#/$defs/CommitLog` | R9 |
| [lists.declaration-list.json](fixtures/lists.declaration-list.json) | `lists.schema.json#/$defs/DeclarationList` | V14 |
| [lists.draft-list.json](fixtures/lists.draft-list.json) | `lists.schema.json#/$defs/DraftList` | D1 |
| [lists.mercure-authorization.json](fixtures/lists.mercure-authorization.json) | `lists.schema.json#/$defs/MercureAuthorization` | A3 |
| [lists.repository-page.json](fixtures/lists.repository-page.json) | `lists.schema.json#/$defs/RepositoryPage` | R1, `list_repositories` |
| [lists.request-page.json](fixtures/lists.request-page.json) | `lists.schema.json#/$defs/RequestPage` | Q1, `list_requests` |
| [lists.review-page.json](fixtures/lists.review-page.json) | `lists.schema.json#/$defs/ReviewPage` | V2, `list_reviews` |
| [lists.search-hit-list.json](fixtures/lists.search-hit-list.json) | `lists.schema.json#/$defs/SearchHitList` | V16 |
| [lists.symbol-list.json](fixtures/lists.symbol-list.json) | `lists.schema.json#/$defs/SymbolList` | V9 |
| [lists.token-list.json](fixtures/lists.token-list.json) | `lists.schema.json#/$defs/TokenList` | T1 |
| [lists.tree-listing.json](fixtures/lists.tree-listing.json) | `lists.schema.json#/$defs/TreeListing` | V6 |
| [lists.viewed-files.json](fixtures/lists.viewed-files.json) | `lists.schema.json#/$defs/ViewedFiles` | V11, V12 |
| [live-update.candidates-changed.json](fixtures/live-update.candidates-changed.json) | `live-update.schema.json` | Repositories topic |
| [live-update.events-batch.json](fixtures/live-update.events-batch.json) | `live-update.schema.json` | Review topic |
| [live-update.events.json](fixtures/live-update.events.json) | `live-update.schema.json` | Review topic |
| [live-update.index.json](fixtures/live-update.index.json) | `live-update.schema.json` | Review topic |
| [live-update.outdated.json](fixtures/live-update.outdated.json) | `live-update.schema.json` | Review topic |
| [live-update.pull-request.json](fixtures/live-update.pull-request.json) | `live-update.schema.json` | Review topic |
| [live-update.repository-deleted.json](fixtures/live-update.repository-deleted.json) | `live-update.schema.json` | Repositories topic |
| [live-update.repository.json](fixtures/live-update.repository.json) | `live-update.schema.json` | Repositories topic |
| [me.agent.json](fixtures/me.agent.json) | `me.schema.json` | A1 with a scoped token |
| [me.local-user.json](fixtures/me.local-user.json) | `me.schema.json` | A1 with a local sign-in session |
| [repository.github-cloning.json](fixtures/repository.github-cloning.json) | `repository.schema.json` | R3, R4, R5 |
| [repository.local-idle.json](fixtures/repository.local-idle.json) | `repository.schema.json` | R3, R4, R5 |
| [request-command-result.claim.json](fixtures/request-command-result.claim.json) | `request-command-result.schema.json` | Q5, `claim_request` |
| [request-command-result.proposed.json](fixtures/request-command-result.proposed.json) | `request-command-result.schema.json` | Q14, `propose_solution` |
| [request-command-result.question.json](fixtures/request-command-result.question.json) | `request-command-result.schema.json` | Q4, `reply_to_request` |
| [request-command-result.ready.json](fixtures/request-command-result.ready.json) | `request-command-result.schema.json` | Q10, `mark_ready_for_review` |
| [request-command-result.release.json](fixtures/request-command-result.release.json) | `request-command-result.schema.json` | Q6, `release_request` |
| [request-command-result.retitled.json](fixtures/request-command-result.retitled.json) | `request-command-result.schema.json` | Q23, `set_request_title` |
| [request-command-result.suggested.json](fixtures/request-command-result.suggested.json) | `request-command-result.schema.json` | Q20, `suggest_request` |
| [request.carried.json](fixtures/request.carried.json) | `request.schema.json` | Q3 of a carried request, `get_request` |
| [request.general.json](fixtures/request.general.json) | `request.schema.json` | Q2, Q3, `get_request` |
| [request.in-progress.json](fixtures/request.in-progress.json) | `request.schema.json` | Q3 with `at`, `get_request` |
| [request.opened-range.json](fixtures/request.opened-range.json) | `request.schema.json` | Q2 |
| [request.proposal-pending.json](fixtures/request.proposal-pending.json) | `request.schema.json` | Q3 of a request with a pending proposal, read by the user |
| [request.pull-request.json](fixtures/request.pull-request.json) | `request.schema.json` | Q3 with `at` of a request with a linked pull request, `get_request` |
| [request.suggestion-pending.json](fixtures/request.suggestion-pending.json) | `request.schema.json` | Q3 of a pending suggestion, read by the user |
| [request.with-excerpt.json](fixtures/request.with-excerpt.json) | `request.schema.json` | Q3 with `context`, `get_request` |
| [review.closed.json](fixtures/review.closed.json) | `review.schema.json` | V3, V4 |
| [review.index-status.json](fixtures/review.index-status.json) | `review.schema.json#/$defs/IndexStatus` | V5 |
| [review.open.json](fixtures/review.open.json) | `review.schema.json` | V1, V3, `start_review` |
| [updates-page.cursor.json](fixtures/updates-page.cursor.json) | `updates-page.schema.json` | V10 with a cursor, `get_updates` |
| [updates-page.first-call.json](fixtures/updates-page.first-call.json) | `updates-page.schema.json` | V10 without a cursor, `get_updates` |
