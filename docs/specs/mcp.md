# Slopmower MCP server v1

The MCP server gives coding agents the agent side of the request workflow. It calls the same application services as the REST API and returns the same JSON. Conventions, errors, and versioning are in [README.md](README.md).

## 1. Endpoint and authentication

| Item | Value |
|---|---|
| Endpoint | `https://<host>/_mcp`, Streamable HTTP, through the HTTP transport of `symfony/mcp-bundle` 0.14.1 with `mcp/sdk` 0.8.1. The transport needs `nyholm/psr7`. The local development environment serves `http://localhost:8000/_mcp`. |
| Server name, version | `slopmower`, `1.0.0` |
| Protocol | The handshake era up to 2025-11-25 with MCP sessions, and 2026-07-28 with `server/discover` |
| Sessions | The `file` session store of the bundle, in `var/mcp-sessions`. The default directory is under `%kernel.cache_dir%`, which `cache:clear` removes, so the configuration sets `directory`. One `php` container serves `/_mcp`, so a file store is enough. A shared store is the backlog item `docs/backlog/mcp-session-store.md`. |
| Authentication | Firewall `mcp` with pattern `^/_mcp`, `stateless: true`, and `access_token` with the same token handler as REST. A token scoped to one repository sees only that repository. |
| Actor | Always `agent`: `agentName` is the token name, `userId` is the token owner. A request that the agent opens is a suggestion. |
| Wrong token | HTTP 401 before JSON-RPC, with `WWW-Authenticate: Bearer realm="slopmower", error="invalid_token"` and an Error JSON body. There is no `resource_metadata` parameter. `/.well-known/oauth-protected-resource` answers 404. MCP OAuth comes later: decision D18 and the backlog item `docs/backlog/mcp-oauth.md`. |
| Rate limit | Each `tools/call` consumes the limiters `api_token` and `api_user`, and a refusal is an `isError` result with `rate_limited`. Each HTTP request consumes `mcp_http`, whose refusal is HTTP 429 before JSON-RPC, with `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and an Error JSON body. See [README.md](README.md#rate-limits). |

Client configuration in the repository of the developer:

```json
{"mcpServers":{"slopmower":{"type":"http","url":"https://<host>/_mcp","headers":{"Authorization":"Bearer ${SLOPMOWER_TOKEN}"}}}}
```

Bundle configuration, `config/packages/mcp.yaml`:

```yaml
mcp:
  servers:
    slopmower:
      name: slopmower
      version: 1.0.0
      description: Change requests from Slopmower, for the coding agent that implements them.
      instructions: '%app.mcp_instructions%'   # the string in section 2
      transports: { stdio: false, http: true }
      http: { path: /_mcp, allowed_hosts: ['%env(APP_PUBLIC_HOST)%'] }
      session: { store: file, directory: '%kernel.project_dir%/var/mcp-sessions' }
      registry: { tools: ['App\Mcp\McpTools'] }
```

`http.allowed_hosts` defaults to localhost only, so a public server must list its host.

## 2. Server instructions

```text
Slopmower stores change requests that the user wrote while reviewing this repository at a pinned commit. Each request asks for one change and belongs to exactly one review; several reviews can be open at once.

1. Identify the repository: owner/name from `git remote get-url origin` for GitHub, or the name of the repository's root directory for a local repository. list_repositories shows what this token can see.
2. list_reviews(repository) returns the open reviews. Work on the one the user named; if several are open and none was named, ask.
3. To review code, start_review or use the named review, read the code at its commitSha, and call suggest_request once per finding with the narrowest anchor. Do not edit code for a suggestion. A pending suggestion allows only reply_to_request.
4. get_updates(review) without a cursor returns what waits for you. Keep the returned cursor and pass it in the next call; if hasMore is true, call again at once.
5. For each request with awaiting "agent", reopened ones first: get_request, then reply_to_request (question: true when you need an answer, then move on), or claim_request before you edit code.
6. When requireApproval is true, or the request is unclear or large, propose_solution after the claim and take the next request. The decision comes back through get_updates. Link no commit before proposal.state is approved; on "rejected", read the note and propose again.
7. Commit per request, then link_commits with the full SHA and every request that the commit covers. GitHub repositories: push before mark_ready_for_review. Local repositories: committing is enough. If you opened a pull request for the work, link_pull_request with its URL and every request that it covers; unlink_pull_request removes a link made by mistake.
8. Finish with mark_ready_for_review (what changed, how you tested it), or mark_wont_do with evidence the user can check, or release_request if you stop.

Never verify, reopen, accept or dismiss suggestions, or decide on proposals. Open requests only with suggest_request. Errors are isError results whose text is {"error":{"code","message","details"}}. On invalid_transition call get_request and read allowedActions and proposal. On review_closed stop working on that review.
```

## 3. Conventions

- **Adapter.** There is one `#[McpTool]` method per operation. Parameters are scalars, backed enums, and lists of strings, because the SDK does not support object parameters. Each method calls the same application service as REST and returns the normalized array. So `structuredContent` equals the REST JSON, and `content[0].text` holds the same data as JSON text.
- **Titles and descriptions.** Each `#[McpTool]` sets `title:` and `description:` to the texts given for the tool below. Without `description:`, the SDK uses the summary and body of the method docblock. The snippets below do not repeat the description.
- **Output schemas.** Each output schema is a bundled copy of a file in [schemas/](schemas/): the root with `type: "object"`, `properties`, and `required` at the top level, plus a `$defs` section with every referenced definition, with each `$ref` rewritten to `#/$defs/<Name>`. The command `bin/console app:schema:bundle` generates `src/Mcp/BundledSchemas.php` with one `public const array` per root, because attribute arguments must be constant expressions. A test fails when the generated file differs from the output of the command.
- **Annotations.** All tools set `destructiveHint: false` and `openWorldHint: false`. The read tools set `readOnlyHint: true` and `idempotentHint: true`. The table in section 4 lists them per tool.
- **Domain errors.** The adapter catches `DomainError` and throws `ToolCallException(json_encode(['error' => ['code' => ..., 'message' => ..., 'details' => ...]]))`. The SDK turns that into `{"isError": true, "content": [{"type": "text", "text": "<Error JSON>"}]}`, with **no `structuredContent`**. So clients that validate `structuredContent` against the `outputSchema` do not reject the error.
- **Rate limits.** The adapter consumes the limiters of [README.md](README.md#rate-limits) before each tool runs, so every tool can return `rate_limited`. The tool lists below do not repeat it.
- **Other exceptions.** The adapter catches every other `Throwable`, logs it, and throws `ToolCallException` with code `internal_error`. The agent therefore never gets a bare -32603.
- **Protocol-level errors.** The SDK answers these before the tool runs. An unknown tool gives JSON-RPC error -32602 without `data`. Arguments that violate `inputSchema` give -32602 with `data.validation_errors`. Any `inputSchema` keyword can fail, for example `pattern`, `minLength`, `maximum`, `enum`, `type`, or a missing required field. Semantic checks give `isError` with `invalid_input`, for example a body of whitespace only, or a reason shorter than 20 characters after trimming.
- **Constants** of `App\Mcp\McpTools` used in `#[Schema]`:
  - `ULID = '^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$'`
  - `FULL_SHA = '^([0-9a-f]{40}|[0-9a-f]{64})$'`
  - `SHA_PREFIX = '^[0-9a-f]{7,64}$'`
  - `PULL_REQUEST_URL = '^https://\S+$'`
- **Schema generation.** In SDK 0.8.1, `?string $x = null` generates `{"type":["null","string"],"default":null}`, and `?int` generates `["null","integer"]`: null comes first. A nullable backed enum generates `{"type":["string","null"],"enum":[..., null],"default":null}`. A parameter without a default is required. An empty `required` list is left out. The docblock type `list<string>` produces an empty `items` schema `{}`, so array parameters need `#[Schema(items: ...)]`.

## 4. Tools

The `inputSchema` blocks below are what the SDK 0.8.1 `SchemaGenerator` generates from the signatures, including its type order. A snapshot test of `tools/list` confirms them. The parameter descriptions come from the `@param` docblocks.

| Tool | Title | `readOnlyHint` | `idempotentHint` | Output schema | REST |
|---|---|---|---|---|---|
| [`list_repositories`](#41-list_repositories) | List repositories | true | true | [`lists.schema.json#/$defs/RepositoryPage`](schemas/lists.schema.json) | [R1](rest-api.md#r1-list-repositories) |
| [`list_reviews`](#42-list_reviews) | List reviews | true | true | [`lists.schema.json#/$defs/ReviewPage`](schemas/lists.schema.json) | [V2](rest-api.md#v2-list-reviews) |
| [`list_requests`](#43-list_requests) | List requests | true | true | [`lists.schema.json#/$defs/RequestPage`](schemas/lists.schema.json) | [Q1](rest-api-requests.md#q1-list-requests) |
| [`get_updates`](#44-get_updates) | Get updates | true | true | [`updates-page.schema.json`](schemas/updates-page.schema.json) | [V10](rest-api-requests.md#4-updates) |
| [`get_request`](#45-get_request) | Get request | true | true | [`request.schema.json`](schemas/request.schema.json) | [Q3](rest-api-requests.md#q3-get-a-request) |
| [`claim_request`](#46-claim_request) | Claim request | false | true | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) | [Q5](rest-api-requests.md#q5-claim-a-request) |
| [`release_request`](#47-release_request) | Release request | false | false | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) | [Q6](rest-api-requests.md#q6-release-a-request) |
| [`reply_to_request`](#48-reply_to_request) | Reply to request | false | false | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) | [Q4](rest-api-requests.md#q4-comment-on-a-request) |
| [`link_commits`](#49-link_commits) | Link commits | false | true | [`link-commits-result.schema.json`](schemas/link-commits-result.schema.json) | [Q7](rest-api-requests.md#q7-link-a-commit-to-requests) |
| [`unlink_commit`](#410-unlink_commit) | Unlink commit | false | false | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) | [Q8](rest-api-requests.md#q8-unlink-a-commit) |
| [`mark_ready_for_review`](#411-mark_ready_for_review) | Mark ready for review | false | false | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) | [Q10](rest-api-requests.md#q10-mark-ready-for-review) |
| [`mark_wont_do`](#412-mark_wont_do) | Mark won't do | false | false | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) | [Q11](rest-api-requests.md#q11-mark-wont-do) |
| [`propose_solution`](#413-propose_solution) | Propose solution | false | false | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) | [Q14](rest-api-requests.md#q14-propose-a-solution) |
| [`link_pull_request`](#414-link_pull_request) | Link pull request | false | true | [`link-pull-request-result.schema.json`](schemas/link-pull-request-result.schema.json) | [Q17](rest-api-requests.md#q17-link-a-pull-request-to-requests) |
| [`unlink_pull_request`](#415-unlink_pull_request) | Unlink pull request | false | false | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) | [Q19](rest-api-requests.md#q19-unlink-a-pull-request) |
| [`start_review`](#416-start_review) | Start review | false | false | [`review.schema.json`](schemas/review.schema.json) | [V1](rest-api.md#v1-start-a-review) |
| [`suggest_request`](#417-suggest_request) | Suggest request | false | false | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) | [Q20](rest-api-requests.md#q20-suggest-a-request) |

Every tool sets `destructiveHint: false` and `openWorldHint: false`.

### 4.1 `list_repositories`

- **Title:** `List repositories`
- **Description:**

```text
List the repositories that this token can see: id, source (github or local), name, default branch, and number of open reviews. Use the name or id as `repository` in list_reviews.
```

```php
/**
 * @param ?string $cursor nextCursor of the previous page
 * @param int     $limit  Page size
 */
#[McpTool(name: 'list_repositories', title: 'List repositories', annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false), outputSchema: BundledSchemas::REPOSITORY_PAGE)]
public function listRepositories(#[Schema(minLength: 1)] ?string $cursor = null, #[Schema(minimum: 1, maximum: 500)] int $limit = 100): array
```

```json
{"type":"object","properties":{
 "cursor":{"type":["null","string"],"description":"nextCursor of the previous page","default":null,"minLength":1},
 "limit":{"type":"integer","description":"Page size","default":100,"minimum":1,"maximum":500}}}
```

- **Output:** [`lists.schema.json#/$defs/RepositoryPage`](schemas/lists.schema.json).
- **Behaviour:** as REST R1 without the `name` and `source` filters.
- **Errors:** `invalid_input` for the cursor.
- **Example:** arguments `{}`. `structuredContent`: [fixtures/lists.repository-page.json](fixtures/lists.repository-page.json).

### 4.2 `list_reviews`

- **Title:** `List reviews`
- **Description:**

```text
List the reviews of one repository, newest first. Only open reviews unless includeClosed is true. Each review is pinned to one commit and holds its own requests; pass its id as `review` to get_updates and list_requests.
```

```php
/**
 * @param string  $repository    Repository ID, GitHub owner/name, or local directory name
 * @param bool    $includeClosed Also list closed reviews
 * @param ?string $cursor        nextCursor of the previous page
 * @param int     $limit         Page size
 */
#[McpTool(name: 'list_reviews', title: 'List reviews', annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false), outputSchema: BundledSchemas::REVIEW_PAGE)]
public function listReviews(#[Schema(minLength: 1, maxLength: 255)] string $repository, bool $includeClosed = false, #[Schema(minLength: 1)] ?string $cursor = null, #[Schema(minimum: 1, maximum: 100)] int $limit = 50): array
```

```json
{"type":"object","properties":{
 "repository":{"type":"string","description":"Repository ID, GitHub owner/name, or local directory name","minLength":1,"maxLength":255},
 "includeClosed":{"type":"boolean","description":"Also list closed reviews","default":false},
 "cursor":{"type":["null","string"],"description":"nextCursor of the previous page","default":null,"minLength":1},
 "limit":{"type":"integer","description":"Page size","default":50,"minimum":1,"maximum":100}},
 "required":["repository"]}
```

- **Output:** [`lists.schema.json#/$defs/ReviewPage`](schemas/lists.schema.json).
- **Behaviour:** `repository` is resolved as in [README.md section 7](README.md#7-repository-sources-names-and-commit-availability). Otherwise as REST V2 with `state=open`, or `state=all` when `includeClosed` is true.
- **Errors:** `not_found` for the repository, `invalid_input`.
- **Example:** arguments `{"repository":"acme/billing"}`. `structuredContent`: [fixtures/lists.review-page.json](fixtures/lists.review-page.json).

### 4.3 `list_requests`

- **Title:** `List requests`
- **Description:**

```text
List the change requests of one review, oldest first, with optional filters. Returns summaries; call get_request for the body, thread, anchor, and location.
```

```php
/**
 * @param string           $review     Review ID from list_reviews
 * @param ?RequestStatus   $status     Only requests with this status
 * @param ?Awaiting        $awaiting   Only requests where it is this party's turn; "agent" means you
 * @param ?string          $path       Only requests whose file path starts with this prefix; general requests never match
 * @param ?bool            $outdated   Only requests whose code changed (true) or did not change (false) on the default branch
 * @param ?SuggestionState $suggestion Only suggestions in this state; requests that the user opened never match
 * @param ?string          $cursor     nextCursor of the previous page
 * @param int              $limit      Page size
 */
#[McpTool(name: 'list_requests', title: 'List requests', annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false), outputSchema: BundledSchemas::REQUEST_PAGE)]
public function listRequests(#[Schema(pattern: self::ULID)] string $review, ?RequestStatus $status = null, ?Awaiting $awaiting = null, #[Schema(minLength: 1, maxLength: 4096)] ?string $path = null, ?bool $outdated = null, ?SuggestionState $suggestion = null, #[Schema(minLength: 1)] ?string $cursor = null, #[Schema(minimum: 1, maximum: 200)] int $limit = 50): array
```

```json
{"type":"object","properties":{
 "review":{"type":"string","description":"Review ID from list_reviews","pattern":"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"},
 "status":{"type":["string","null"],"description":"Only requests with this status","default":null,"enum":["open","in_progress","ready_for_review","verified","reopened","wont_do",null]},
 "awaiting":{"type":["string","null"],"description":"Only requests where it is this party's turn; \"agent\" means you","default":null,"enum":["agent","user","none",null]},
 "path":{"type":["null","string"],"description":"Only requests whose file path starts with this prefix; general requests never match","default":null,"minLength":1,"maxLength":4096},
 "outdated":{"type":["null","boolean"],"description":"Only requests whose code changed (true) or did not change (false) on the default branch","default":null},
 "suggestion":{"type":["string","null"],"description":"Only suggestions in this state; requests that the user opened never match","default":null,"enum":["pending","accepted","dismissed",null]},
 "cursor":{"type":["null","string"],"description":"nextCursor of the previous page","default":null,"minLength":1},
 "limit":{"type":"integer","description":"Page size","default":50,"minimum":1,"maximum":200}},
 "required":["review"]}
```

- **Output:** [`lists.schema.json#/$defs/RequestPage`](schemas/lists.schema.json).
- **Behaviour:** REST Q1 with `sort=created`.
- **Errors:** `not_found`, `invalid_input` for a cursor sent with other filters.
- **Example:** arguments `{"review":"01K6B3A1B2C3D4E5F6G7H8J9KM"}`. `structuredContent`: [fixtures/lists.request-page.json](fixtures/lists.request-page.json).

### 4.4 `get_updates`

- **Title:** `Get updates`
- **Description:**

```text
Return what changed in one review since `cursor`, grouped by request, leaving out your own actions. Without a cursor, return every request that waits for you with its unseen events. Always keep the returned cursor for the next call; if hasMore is true, call again at once. reviewState 'closed' means stop.
```

```php
/**
 * @param string $review Review ID from list_reviews
 * @param ?int   $cursor The cursor from the previous call. Null returns everything that waits for you.
 * @param int    $limit  Maximum events (with a cursor) or requests (without one)
 */
#[McpTool(name: 'get_updates', title: 'Get updates', annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false), outputSchema: BundledSchemas::UPDATES_PAGE)]
public function getUpdates(#[Schema(pattern: self::ULID)] string $review, #[Schema(minimum: 0)] ?int $cursor = null, #[Schema(minimum: 1, maximum: 500)] int $limit = 100): array
```

```json
{"type":"object","properties":{
 "review":{"type":"string","description":"Review ID from list_reviews","pattern":"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"},
 "cursor":{"type":["null","integer"],"description":"The cursor from the previous call. Null returns everything that waits for you.","default":null,"minimum":0},
 "limit":{"type":"integer","description":"Maximum events (with a cursor) or requests (without one)","default":100,"minimum":1,"maximum":500}},
 "required":["review"]}
```

- **Output:** [`updates-page.schema.json`](schemas/updates-page.schema.json).
- **Behaviour:** the algorithm of [rest-api-requests.md section 4](rest-api-requests.md#4-updates) with `includeOwn=false`.
- **Errors:** `not_found`, `invalid_input` for a cursor ahead of the server.
- **Example:** arguments `{"review":"01K6B3A1B2C3D4E5F6G7H8J9KM"}` give [fixtures/updates-page.first-call.json](fixtures/updates-page.first-call.json). Arguments `{"review":"01K6B3A1B2C3D4E5F6G7H8J9KM","cursor":1842}` give [fixtures/updates-page.cursor.json](fixtures/updates-page.cursor.json).

### 4.5 `get_request`

- **Title:** `Get request`
- **Description:**

```text
Return one request in full: body, thread, anchor with a code snapshot at the review commit, linked commits, location, and the actions you may take now (allowedActions). `at` locates the anchor at another commit that the server's clone has (pushed, or committed in a local repository); by default the location is at the default-branch head. Your working tree can differ: find the code with anchor.snapshot, its before and after lines, and anchor.symbol.fqn. A general request has anchor, location, and excerpt null. It belongs to the whole review, and its body and thread say what to change.
```

```php
/**
 * @param string  $id      Request ID
 * @param ?string $at      Commit SHA (7-64 hex digits) to locate the anchor at
 * @param ?int    $context Lines of context around the located code to include as excerpt
 */
#[McpTool(name: 'get_request', title: 'Get request', annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false), outputSchema: BundledSchemas::REQUEST)]
public function getRequest(#[Schema(pattern: self::ULID)] string $id, #[Schema(pattern: self::SHA_PREFIX)] ?string $at = null, #[Schema(minimum: 0, maximum: 50)] ?int $context = null): array
```

```json
{"type":"object","properties":{
 "id":{"type":"string","description":"Request ID","pattern":"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"},
 "at":{"type":["null","string"],"description":"Commit SHA (7-64 hex digits) to locate the anchor at","default":null,"pattern":"^[0-9a-f]{7,64}$"},
 "context":{"type":["null","integer"],"description":"Lines of context around the located code to include as excerpt","default":null,"minimum":0,"maximum":50}},
 "required":["id"]}
```

- **Output:** [`request.schema.json`](schemas/request.schema.json).
- **Behaviour:** REST Q3. `pullRequests` holds the state and the title of each linked pull request.
- **Errors:** `not_found`, `invalid_input` for an ambiguous prefix, `commit_not_found`, `repository_unavailable`.
- **Example:** arguments `{"id":"01K6B3P2A9QRSTVWXYZ0123456","at":"a1b2c3d4e5f60718293a4b5c6d7e8f9012345678"}` give [fixtures/request.in-progress.json](fixtures/request.in-progress.json). Arguments `{"id":"01K6B3P2A9QRSTVWXYZ0123457","context":2}` give [fixtures/request.with-excerpt.json](fixtures/request.with-excerpt.json).

### 4.6 `claim_request`

- **Title:** `Claim request`
- **Description:**

```text
Claim an open or reopened request before you edit code for it. Status becomes in_progress. Claiming a request that you already hold succeeds and changes nothing. Fails with invalid_transition when the status does not allow it or another agent holds it: then take the next request.
```

```php
/** @param string $id Request ID */
#[McpTool(name: 'claim_request', title: 'Claim request', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false), outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT)]
public function claimRequest(#[Schema(pattern: self::ULID)] string $id): array
```

```json
{"type":"object","properties":{"id":{"type":"string","description":"Request ID","pattern":"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"}},"required":["id"]}
```

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q5.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`.
- **Example:** arguments `{"id":"01K6B3P2A9QRSTVWXYZ0123456"}` give [fixtures/request-command-result.claim.json](fixtures/request-command-result.claim.json). The error when another agent holds the request: [fixtures/error.invalid-transition.json](fixtures/error.invalid-transition.json).

### 4.7 `release_request`

- **Title:** `Release request`
- **Description:**

```text
Give back a request that you claimed but will not finish. Its status returns to what it was before the claim (open or reopened).
```

```php
/**
 * @param string  $id   Request ID
 * @param ?string $note Why you stop, for the user
 */
#[McpTool(name: 'release_request', title: 'Release request', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false), outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT)]
public function releaseRequest(#[Schema(pattern: self::ULID)] string $id, #[Schema(maxLength: 65536)] ?string $note = null): array
```

```json
{"type":"object","properties":{
 "id":{"type":"string","description":"Request ID","pattern":"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"},
 "note":{"type":["null","string"],"description":"Why you stop, for the user","default":null,"maxLength":65536}},
 "required":["id"]}
```

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q6.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`.
- **Example:** arguments `{"id":"01K6B3P2A9QRSTVWXYZ0123457","note":"The fix needs a schema change. I stop here."}` give [fixtures/request-command-result.release.json](fixtures/request-command-result.release.json).

### 4.8 `reply_to_request`

- **Title:** `Reply to request`
- **Description:**

```text
Add a comment to a request's thread, in any status. Set question to true when you need the user's answer; the request then waits for the user and comes back through get_updates when they reply.
```

```php
/**
 * @param string $id       Request ID
 * @param string $body     Markdown comment
 * @param bool   $question True when you need an answer from the user
 */
#[McpTool(name: 'reply_to_request', title: 'Reply to request', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false), outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT)]
public function replyToRequest(#[Schema(pattern: self::ULID)] string $id, #[Schema(minLength: 1, maxLength: 65536)] string $body, bool $question = false): array
```

```json
{"type":"object","properties":{
 "id":{"type":"string","description":"Request ID","pattern":"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"},
 "body":{"type":"string","description":"Markdown comment","minLength":1,"maxLength":65536},
 "question":{"type":"boolean","description":"True when you need an answer from the user","default":false}},
 "required":["id","body"]}
```

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q4.
- **Errors:** `not_found`, `review_closed`, `invalid_input` for a body of whitespace only.
- **Example:** arguments ``{"id":"01K6B3P2A9QRSTVWXYZ0123457","body":"Should `TaxRounding` also cover the shipping totals?","question":true}`` give [fixtures/request-command-result.question.json](fixtures/request-command-result.question.json).

### 4.9 `link_commits`

- **Title:** `Link commits`
- **Description:**

```text
Record that a commit implements one or more requests that you hold (status in_progress). Use the full SHA from `git rev-parse HEAD`. The commit may be unpushed: the link stays pending until the server's clone has it. Linking does not finish a request.
```

```php
/**
 * @param string   $commitSha  Full commit SHA
 * @param string[] $requestIds Every request that this commit covers, all from one review
 * @param ?string  $note       Optional note shown with the link
 */
#[McpTool(name: 'link_commits', title: 'Link commits', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false), outputSchema: BundledSchemas::LINK_COMMITS_RESULT)]
public function linkCommits(
    #[Schema(pattern: self::FULL_SHA)] string $commitSha,
    #[Schema(items: ['type' => 'string', 'pattern' => self::ULID], minItems: 1, maxItems: 50, uniqueItems: true)] array $requestIds,
    #[Schema(maxLength: 65536)] ?string $note = null,
): array
```

```json
{"type":"object","properties":{
 "commitSha":{"type":"string","description":"Full commit SHA","pattern":"^([0-9a-f]{40}|[0-9a-f]{64})$"},
 "requestIds":{"type":"array","description":"Every request that this commit covers, all from one review","items":{"type":"string","pattern":"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"},"minItems":1,"maxItems":50,"uniqueItems":true},
 "note":{"type":["null","string"],"description":"Optional note shown with the link","default":null,"maxLength":65536}},
 "required":["commitSha","requestIds"]}
```

- **Output:** [`link-commits-result.schema.json`](schemas/link-commits-result.schema.json).
- **Behaviour:** REST Q7. One commit, 1 to 50 request IDs of one review, full SHA only. Linking an existing pair again is a no-op.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`, `invalid_input` for requests from several reviews.
- **Example:** arguments `{"commitSha":"a1b2c3d4e5f60718293a4b5c6d7e8f9012345678","requestIds":["01K6B3P2A9QRSTVWXYZ0123456","01K6B3P2A9QRSTVWXYZ0123457"],"note":"One refactoring covers both."}` give [fixtures/link-commits-result.available.json](fixtures/link-commits-result.available.json). An unpushed commit gives [fixtures/link-commits-result.pending.json](fixtures/link-commits-result.pending.json).

### 4.10 `unlink_commit`

- **Title:** `Unlink commit`
- **Description:**

```text
Remove a commit link that you made by mistake from a request that you hold.
```

```php
/**
 * @param string $commitSha Full commit SHA
 * @param string $requestId Request ID
 */
#[McpTool(name: 'unlink_commit', title: 'Unlink commit', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false), outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT)]
public function unlinkCommit(#[Schema(pattern: self::FULL_SHA)] string $commitSha, #[Schema(pattern: self::ULID)] string $requestId): array
```

```json
{"type":"object","properties":{
 "commitSha":{"type":"string","description":"Full commit SHA","pattern":"^([0-9a-f]{40}|[0-9a-f]{64})$"},
 "requestId":{"type":"string","description":"Request ID","pattern":"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"}},
 "required":["commitSha","requestId"]}
```

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q8.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`, `invalid_input` when the commit is not linked.
- **Example:** arguments `{"commitSha":"e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e","requestId":"01K6B3P2A9QRSTVWXYZ0123457"}`. The result has the shape of [fixtures/request-command-result.claim.json](fixtures/request-command-result.claim.json) with one `commit.unlinked` event.

### 4.11 `mark_ready_for_review`

- **Title:** `Mark ready for review`
- **Description:**

```text
Tell the user that a request you hold is done. The note says what you changed and how you tested it. Every linked commit must be in the server's clone: for GitHub repositories push first (the server fetches once), otherwise this fails with commits_not_available. A request that needs no code change may have no commits; say why in the note.
```

```php
/**
 * @param string $id   Request ID
 * @param string $note What you changed and how you tested it
 */
#[McpTool(name: 'mark_ready_for_review', title: 'Mark ready for review', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false), outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT)]
public function markReadyForReview(#[Schema(pattern: self::ULID)] string $id, #[Schema(minLength: 1, maxLength: 65536)] string $note): array
```

```json
{"type":"object","properties":{
 "id":{"type":"string","description":"Request ID","pattern":"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"},
 "note":{"type":"string","description":"What you changed and how you tested it","minLength":1,"maxLength":65536}},
 "required":["id","note"]}
```

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q10.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`, `commits_not_available`, `repository_unavailable`, `invalid_input`.
- **Example:** arguments `{"id":"01K6B3P2A9QRSTVWXYZ0123456","note":"..."}` give [fixtures/request-command-result.ready.json](fixtures/request-command-result.ready.json). A pending link gives [fixtures/error.commits-not-available.json](fixtures/error.commits-not-available.json).

### 4.12 `mark_wont_do`

- **Title:** `Mark won't do`
- **Description:**

```text
Decline an open or reopened request, or one that you hold. Give evidence that the user can check: a file and line, a test result, a conflicting requirement, or why the request is wrong. At least 20 characters. The user then verifies or reopens it.
```

```php
/**
 * @param string $id     Request ID
 * @param string $reason Checkable justification, at least 20 characters
 */
#[McpTool(name: 'mark_wont_do', title: "Mark won't do", annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false), outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT)]
public function markWontDo(#[Schema(pattern: self::ULID)] string $id, #[Schema(minLength: 20, maxLength: 65536)] string $reason): array
```

```json
{"type":"object","properties":{
 "id":{"type":"string","description":"Request ID","pattern":"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"},
 "reason":{"type":"string","description":"Checkable justification, at least 20 characters","minLength":20,"maxLength":65536}},
 "required":["id","reason"]}
```

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q11.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`, `invalid_input` for fewer than 20 characters after trimming.
- **Example:** arguments as in [fixtures/inputs.wont-do.json](fixtures/inputs.wont-do.json) plus `id`. The result has the shape of [fixtures/request-command-result.ready.json](fixtures/request-command-result.ready.json) with status `wont_do`, `awaiting: user`, and one `request.wont_do` event.

### 4.13 `propose_solution`

- **Title:** `Propose solution`
- **Description:**

```text
Propose how you will solve a request that you hold, before you link commits. Required when the request has requireApproval true; also useful when the request is unclear or large. A new proposal replaces the current one. The user approves it or requests changes: the decision comes back through get_updates, and get_request shows it in proposal.state. Until the user approves, link_commits and mark_ready_for_review fail with invalid_transition.
```

```php
/**
 * @param string  $id   Request ID
 * @param string  $plan Markdown plan: what you will change, and why
 * @param ?string $diff Optional unified diff of the change from `git diff`, at most 9 files
 */
#[McpTool(name: 'propose_solution', title: 'Propose solution', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false), outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT)]
public function proposeSolution(#[Schema(pattern: self::ULID)] string $id, #[Schema(minLength: 1, maxLength: 65536)] string $plan, #[Schema(minLength: 1, maxLength: 65536)] ?string $diff = null): array
```

```json
{"type":"object","properties":{
 "id":{"type":"string","description":"Request ID","pattern":"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"},
 "plan":{"type":"string","description":"Markdown plan: what you will change, and why","minLength":1,"maxLength":65536},
 "diff":{"type":["null","string"],"description":"Optional unified diff of the change from `git diff`, at most 9 files","default":null,"minLength":1,"maxLength":65536}},
 "required":["id","plan"]}
```

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q14. The user approves with Q15 or requests changes with Q16. These decisions have no tool.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`, `invalid_input` for a plan of whitespace only or a diff that is not a git diff of 1 to 9 files.
- **Example:** arguments as in [fixtures/inputs.propose-solution.json](fixtures/inputs.propose-solution.json) plus `id` give [fixtures/request-command-result.proposed.json](fixtures/request-command-result.proposed.json).

### 4.14 `link_pull_request`

- **Title:** `Link pull request`
- **Description:**

```text
Record that a pull request implements one or more requests: each one in_progress and held by you, or ready_for_review. For a GitHub repository the URL is https://github.com/{owner}/{name}/pull/{number} of the reviewed repository. Linking does not change the status. Open a pull request only when the user or the repository's instructions ask for it.
```

```php
/**
 * @param string   $url        Pull request URL
 * @param string[] $requestIds Every request that this pull request covers, all from one review
 * @param ?string  $note       Optional note shown with the link
 */
#[McpTool(name: 'link_pull_request', title: 'Link pull request', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false), outputSchema: BundledSchemas::LINK_PULL_REQUEST_RESULT)]
public function linkPullRequest(
    #[Schema(pattern: self::PULL_REQUEST_URL, maxLength: 2048)] string $url,
    #[Schema(items: ['type' => 'string', 'pattern' => self::ULID], minItems: 1, maxItems: 50, uniqueItems: true)] array $requestIds,
    #[Schema(maxLength: 65536)] ?string $note = null,
): array
```

```json
{"type":"object","properties":{
 "url":{"type":"string","description":"Pull request URL","maxLength":2048,"pattern":"^https://\\S+$"},
 "requestIds":{"type":"array","description":"Every request that this pull request covers, all from one review","items":{"type":"string","pattern":"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"},"minItems":1,"maxItems":50,"uniqueItems":true},
 "note":{"type":["null","string"],"description":"Optional note shown with the link","default":null,"maxLength":65536}},
 "required":["url","requestIds"]}
```

- **Output:** [`link-pull-request-result.schema.json`](schemas/link-pull-request-result.schema.json).
- **Behaviour:** REST Q17. One URL, 1 to 50 request IDs of one review. Linking an existing pair again is a no-op; for a GitHub repository the number identifies the pull request. The link changes neither the status nor `awaiting`. For a GitHub repository, the server then reads the state of the pull request for `get_request`.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`, `invalid_input` for requests from several reviews or a pull request of another GitHub repository.
- **Example:** arguments as in [fixtures/inputs.link-pull-request.json](fixtures/inputs.link-pull-request.json) give [fixtures/link-pull-request-result.linked.json](fixtures/link-pull-request-result.linked.json).

### 4.15 `unlink_pull_request`

- **Title:** `Unlink pull request`
- **Description:**

```text
Remove a pull request link that you made by mistake from a request: in_progress and held by you, or ready_for_review.
```

```php
/**
 * @param string $url       Pull request URL
 * @param string $requestId Request ID
 */
#[McpTool(name: 'unlink_pull_request', title: 'Unlink pull request', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false), outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT)]
public function unlinkPullRequest(#[Schema(pattern: self::PULL_REQUEST_URL, maxLength: 2048)] string $url, #[Schema(pattern: self::ULID)] string $requestId): array
```

```json
{"type":"object","properties":{
 "url":{"type":"string","description":"Pull request URL","maxLength":2048,"pattern":"^https://\\S+$"},
 "requestId":{"type":"string","description":"Request ID","pattern":"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"}},
 "required":["url","requestId"]}
```

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q19. For a GitHub repository the number identifies the pull request, and a linked URL with an earlier name of the repository is accepted too. The unlink changes neither the status nor `awaiting`.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`, `invalid_input` when the URL breaks the rule of Q17 and is not a linked URL, or is not linked to the request.
- **Example:** arguments `{"url":"https://github.com/acme/billing/pull/42","requestId":"01K6B3P2A9QRSTVWXYZ0123457"}`. The result has the shape of [fixtures/request-command-result.claim.json](fixtures/request-command-result.claim.json) with one `pull_request.unlinked` event.

### 4.16 `start_review`

- **Title:** `Start review`
- **Description:**

```text
Start a review of one repository, pinned to one commit, when the user asks you to review code and no review was named. Without ref, the review pins the head of the default branch. Read the code at the returned commitSha, then call suggest_request once per finding.
```

```php
/**
 * @param string  $repository Repository ID, GitHub owner/name, or local directory name
 * @param ?string $ref        Branch, tag, or commit SHA to pin; null for the default branch
 * @param ?string $title      Review title; null for "<ref> @ <short SHA>"
 */
#[McpTool(name: 'start_review', title: 'Start review', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false), outputSchema: BundledSchemas::REVIEW)]
public function startReview(#[Schema(minLength: 1, maxLength: 255)] string $repository, #[Schema(minLength: 1, maxLength: 255)] ?string $ref = null, #[Schema(minLength: 1, maxLength: 200)] ?string $title = null): array
```

```json
{"type":"object","properties":{
 "repository":{"type":"string","description":"Repository ID, GitHub owner/name, or local directory name","minLength":1,"maxLength":255},
 "ref":{"type":["null","string"],"description":"Branch, tag, or commit SHA to pin; null for the default branch","default":null,"minLength":1,"maxLength":255},
 "title":{"type":["null","string"],"description":"Review title; null for \"<ref> @ <short SHA>\"","default":null,"minLength":1,"maxLength":200}},
 "required":["repository"]}
```

- **Output:** [`review.schema.json`](schemas/review.schema.json).
- **Behaviour:** `repository` is resolved as in [README.md section 7](README.md#7-repository-sources-names-and-commit-availability). Otherwise as REST V1 with the body `{ref, title}`. `startedBy` is the agent. Only the user closes the review.
- **Errors:** `not_found` for the repository, `invalid_input` for a malformed ref or an ambiguous SHA prefix, `commit_not_found`, `repository_unavailable`, `limit_exceeded` for `open_reviews`.
- **Example:** arguments `{"repository":"acme/billing","ref":"main"}`. The result has the shape of [fixtures/review.open.json](fixtures/review.open.json) with the agent in `startedBy`.

### 4.17 `suggest_request`

- **Title:** `Suggest request`
- **Description:**

```text
Suggest one change while you review code. The suggestion is a request that waits for the user, who accepts or dismisses it; do not edit code for it. Use the narrowest anchor: anchorKind line or range with path, startLine, and endLine; symbol with path and the fully qualified name in symbol, for example App\Billing\InvoiceTotals::total(); file with path. Without anchorKind the suggestion concerns the whole review. If the server does not find the symbol, use a range. An accepted suggestion comes back through get_updates as a request that waits for you.
```

```php
/**
 * @param string      $review     Review ID from start_review or list_reviews
 * @param string      $body       Markdown: what to change, and why
 * @param ?AnchorKind $anchorKind line, range, symbol, or file; null for a general request
 * @param ?string     $path       File path at the review commit; required with anchorKind
 * @param ?int        $startLine  First line, for line and range
 * @param ?int        $endLine    Last line, for line and range; equal to startLine for line
 * @param ?string     $symbol     Fully qualified name of the symbol, for symbol
 */
#[McpTool(name: 'suggest_request', title: 'Suggest request', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false), outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT)]
public function suggestRequest(
    #[Schema(pattern: self::ULID)] string $review,
    #[Schema(minLength: 1, maxLength: 65536)] string $body,
    ?AnchorKind $anchorKind = null,
    #[Schema(minLength: 1, maxLength: 4096)] ?string $path = null,
    #[Schema(minimum: 1)] ?int $startLine = null,
    #[Schema(minimum: 1)] ?int $endLine = null,
    #[Schema(minLength: 1, maxLength: 1024)] ?string $symbol = null,
): array
```

```json
{"type":"object","properties":{
 "review":{"type":"string","description":"Review ID from start_review or list_reviews","pattern":"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"},
 "body":{"type":"string","description":"Markdown: what to change, and why","minLength":1,"maxLength":65536},
 "anchorKind":{"type":["string","null"],"description":"line, range, symbol, or file; null for a general request","default":null,"enum":["line","range","symbol","file",null]},
 "path":{"type":["null","string"],"description":"File path at the review commit; required with anchorKind","default":null,"minLength":1,"maxLength":4096},
 "startLine":{"type":["null","integer"],"description":"First line, for line and range","default":null,"minimum":1},
 "endLine":{"type":["null","integer"],"description":"Last line, for line and range; equal to startLine for line","default":null,"minimum":1},
 "symbol":{"type":["null","string"],"description":"Fully qualified name of the symbol, for symbol","default":null,"minLength":1,"maxLength":1024}},
 "required":["review","body"]}
```

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q20. The flat parameters form the `anchor` of `SuggestRequestInput`: `anchorKind` is `kind`, `path` is `path`, `startLine` and `endLine` form `range`, and `symbol` is `symbol.fqn`. They go to the same anchor capture as Q20, which checks that the kind and the parameters agree. With `anchorKind` null, the suggestion is general, and `path`, `startLine`, `endLine`, and `symbol` must be null.
- **Errors:** `not_found`, `review_closed`, `repository_unavailable`, and `invalid_input` for the anchor errors of Q2, for example parameters that do not agree with `anchorKind`, for a body of whitespace only, or for a 501st pending suggestion in the review, and `limit_exceeded` for `requests_per_review`.
- **Example:** arguments `{"review":"01K6B3A1B2C3D4E5F6G7H8J9KM","anchorKind":"line","path":"src/Billing/InvoiceTotals.php","startLine":55,"endLine":55}` plus the `body` of [fixtures/inputs.suggest-request.json](fixtures/inputs.suggest-request.json) give [fixtures/request-command-result.suggested.json](fixtures/request-command-result.suggested.json).

## 5. Error codes in MCP

| Code | Delivered as | Agent action |
|---|---|---|
| `unauthorized` | HTTP 401 on the transport, never a tool result | Tell the user to check `SLOPMOWER_TOKEN` |
| `not_found` | isError | Check the ID or name, then list again. Repositories outside the token scope also answer `not_found`. |
| `invalid_input` | isError. Schema violations come as JSON-RPC -32602. | Fix the input |
| `invalid_transition` | isError | `get_request`, then read `allowedActions`, `proposal`, `suggestion`, and `details.claimedBy` |
| `review_closed` | isError | Stop working on that review |
| `commits_not_available` | isError | Push, or commit for local repositories, then call `mark_ready_for_review` again |
| `commit_not_found` | isError | Push the commit, or leave out `at` |
| `repository_unavailable` | isError | Tell the user: the repository is cloning, removed, or its source cannot be reached |
| `rate_limited` | isError. A refused HTTP request is HTTP 429 on the transport. | Wait `details.retryAfterSeconds`, then retry |
| `limit_exceeded` | isError, from `start_review` and `suggest_request` | Stop and tell the user |
| `internal_error` | isError | Retry once, then tell the user |
| `forbidden` | never, because all tools are agent tools | none |

## 6. MCP and REST

| Tool | REST |
|---|---|
| `list_repositories` | `GET /api/v1/repositories` |
| `list_reviews` | `GET /api/v1/repositories?name=` then `GET /api/v1/repositories/{id}/reviews?state=open\|all` |
| `list_requests` | `GET /api/v1/reviews/{id}/requests` |
| `get_updates` | `GET /api/v1/reviews/{id}/updates` |
| `get_request` | `GET /api/v1/requests/{id}?at=&context=` |
| `claim_request` | `POST /api/v1/requests/{id}/claim` |
| `release_request` | `POST /api/v1/requests/{id}/release` |
| `reply_to_request` | `POST /api/v1/requests/{id}/comments` |
| `link_commits` | `POST /api/v1/commit-links` |
| `unlink_commit` | `DELETE /api/v1/requests/{id}/commits/{sha}` |
| `mark_ready_for_review` | `POST /api/v1/requests/{id}/ready` |
| `mark_wont_do` | `POST /api/v1/requests/{id}/wont-do` |
| `propose_solution` | `POST /api/v1/requests/{id}/propose` |
| `link_pull_request` | `POST /api/v1/pull-request-links` |
| `unlink_pull_request` | `POST /api/v1/requests/{id}/unlink-pull-request` |
| `start_review` | `GET /api/v1/repositories?name=` then `POST /api/v1/repositories/{id}/reviews` |
| `suggest_request` | `POST /api/v1/reviews/{id}/suggestions` |

`McpRestParityTest` compares the MCP and REST payloads of every tool: `list_repositories`, `list_reviews`, `list_requests`, `get_updates`, `get_request`, and every write tool.

## 7. Wire examples

Success. The request:

```json
{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"claim_request","arguments":{"id":"01K6B3P2A9QRSTVWXYZ0123456"}}}
```

The response. The members `"...": "..."` stand for the rest of [fixtures/request-command-result.claim.json](fixtures/request-command-result.claim.json). `content[0].text` holds the same data as `structuredContent`, as pretty-printed JSON. Tests compare the decoded JSON, not the bytes.

```json
{"jsonrpc":"2.0","id":4,"result":{
 "content":[{"type":"text","text":"{\"request\":{\"id\":\"01K6B3P2A9QRSTVWXYZ0123456\",\"status\":\"in_progress\",\"...\":\"...\"},\"allowedActions\":[\"reply\",\"release\",\"link_commits\",\"link_pull_request\",\"mark_ready_for_review\",\"mark_wont_do\",\"propose_solution\"],\"events\":[{\"seq\":1840,\"type\":\"request.claimed\",\"...\":\"...\"}]}"}],
 "structuredContent":{"request":{"id":"01K6B3P2A9QRSTVWXYZ0123456","status":"in_progress","awaiting":"agent","...":"..."},"allowedActions":["reply","release","link_commits","link_pull_request","mark_ready_for_review","mark_wont_do","propose_solution"],"events":[{"seq":1840,"type":"request.claimed","...":"..."}]},
 "isError":false}}
```

Domain error. The text is [fixtures/error.commits-not-available.json](fixtures/error.commits-not-available.json) as a string. There is no `structuredContent`.

```json
{"jsonrpc":"2.0","id":7,"result":{"content":[{"type":"text","text":"{\"error\":{\"code\":\"commits_not_available\",\"message\":\"1 linked commit is not in the server clone after a fetch. Push it, then try again.\",\"details\":{\"pendingCommits\":[\"e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e\"]}}}"}],"isError":true}}
```

Schema violation, answered by the SDK before the tool runs. The message text comes from opis/json-schema.

```json
{"jsonrpc":"2.0","id":8,"error":{"code":-32602,"message":"Invalid parameters for tool 'mark_wont_do': Property '/reason': Minimum string length is 20, found 9","data":{"validation_errors":[{"pointer":"/reason","keyword":"minLength","message":"Minimum string length is 20, found 9"}]}}}
```
