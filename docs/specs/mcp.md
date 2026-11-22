# Slopmower MCP server v1

The MCP server gives coding agents the agent side of the request workflow. It calls the same application services as the REST API and returns the same JSON. Conventions, errors, and versioning are in [README.md](README.md).

## 1. Endpoint and authentication

| Item | Value |
|---|---|
| Endpoint | `https://<host>/_mcp`, Streamable HTTP, through the HTTP transport of `symfony/mcp-bundle` 0.15.0 with `mcp/sdk` 0.9.0. The transport needs `nyholm/psr7`. The local development environment serves `http://localhost:8000/_mcp`. |
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
      description: Change requests and issues from Slopmower, for the coding agent that works on them.
      instructions: '%app.mcp_instructions%'   # the string in section 2
      transports: { stdio: false, http: true }
      http: { path: /_mcp, allowed_hosts: ['%env(APP_PUBLIC_HOST)%'] }
      session: { store: file, directory: '%kernel.project_dir%/var/mcp-sessions' }
      registry: { tools: ['*'] }               # required by the bundle; no service has an MCP attribute
```

`http.allowed_hosts` defaults to localhost only, so a public server must list its host. `App\Mcp\ToolLoader` registers the tools of section 4.

## 2. Server instructions

```text
Issues: call list_projects. If a project lists this repository, track each user request that changes code, documentation, or configuration as its issue, and follow the skill slopmower-issues. Otherwise use no issue tool. Write short technical English.

Change requests: the user wrote them while reviewing this repository at a pinned commit. Each asks for one change and belongs to one review; several reviews can be open.

1. Identify the repository: owner/name from `git remote get-url origin` for GitHub, or the root directory name for a local one. list_repositories shows what this token sees.
2. list_reviews(repository) returns the open reviews. Use the one the user named; if several are open and none was named, ask.
3. To review code, start_review or use the named review, read the code at its commitSha, and call suggest_request once per finding with the narrowest anchor and a title of at most 80 characters. Edit no code for a suggestion. A pending suggestion allows only reply_to_request.
4. get_updates(review) without a cursor returns what waits for you. Pass the returned cursor in the next call; if hasMore is true, call again now.
5. For each request with awaiting "agent", reopened ones first: get_request, then reply_to_request (questions, one short question each, if you need answers; the user answers each with Yes, No, or text in a comment whose questions hold the answers; then move on), or claim_request before you edit code. If `title` is null, call set_request_title before the claim: one line of at most 80 characters that summarizes the body.
6. Read type in get_request. change: work as below. question: claim_request, answer with reply_to_request, change no code, then mark_ready_for_review with a note that points to the answer; name a needed change in the answer, do not make it. rule: claim_request, add the rule as one bullet in the user's words under `## Rules from Slopmower` in AGENTS.md at the repository root; create it if it is missing. In the same commit, add `@AGENTS.md` to CLAUDE.md if it exists and does not import AGENTS.md, and fix the anchored code if it breaks the rule. Then link_commits and mark_ready_for_review. Follow the rule from then on.
7. Requests with the same group are one unit of feedback: list_requests(review, group) returns them. Claim each, make one change in one commit, link_commits with every request of the group, and mark_ready_for_review on each with the same note. With requireApproval, propose_solution with the same plan on each.
8. With requireApproval, or for an unclear or large request, propose_solution after the claim and take the next request. The decision comes through get_updates. Link no commit before proposal.state is approved. A user comment on a pending proposal asks for a change or a clarification: answer with reply_to_request or propose again. On "rejected", read the note and propose again.
9. Work where delivery.workspace of get_request says: current_branch, the checked-out branch; new_branch, one branch per review, named by you; worktree, a git worktree with its own branch; null, your choice. Commit per request, then link_commits with the full SHA and every request it covers. GitHub: push before mark_ready_for_review. delivery.pullRequest true: open a pull request, then link_pull_request with its URL and every request it covers; false: open none; null: open one only if the user or the repository's instructions ask. With current_branch on the default branch and pullRequest true, ask in the thread first. unlink_pull_request removes a wrong link. Local repositories: a commit is enough; they have no pull requests.
10. Finish with mark_ready_for_review (what changed, how you tested it), mark_wont_do with evidence the user can check, or release_request if you stop.

Never verify, reopen, accept or dismiss suggestions, or decide on proposals. Open requests only with suggest_request. Errors are isError results whose text is {"error":{"code","message","details"}}. On invalid_transition call get_request and read allowedActions and proposal. On review_closed stop work on that review.
```

## 3. Conventions

- **One definition.** [mcp-tools.json](mcp-tools.json) holds each tool in the order of `tools/list`: `name`, `title`, `description`, `inputSchema`, `annotations`, and `outputSchema` as a reference into [schemas/](schemas/). The members other than `outputSchema` are what `tools/list` answers. `ToolsListContractTest` compares them (D99).
- **Adapter.** `App\Mcp\ToolLoader` registers each tool with the method of `App\Mcp\McpTools` whose name is the camel case of the tool name, for example `claimRequest`. Its parameters are the input properties in order, with a default exactly for each property that is not required, and a test checks that. The SDK casts each argument to its parameter type, so parameters are scalars, backed enums, lists of strings, and the `questions` list of `reply_to_request`, whose objects the input schema checks. Each method calls the same application service as REST and returns the normalized array. So `structuredContent` equals the REST JSON, and `content[0].text` holds the same data as JSON text. The issue tools of sections 4.19 to 4.28 call `App\Application\IssueCommands` and `App\Application\Query\IssueQuery`, as the REST endpoints of [rest-api-issues.md](rest-api-issues.md) do. Eight of them have a REST endpoint: `list_projects`, `list_issues`, `get_issue`, `create_issue`, `update_issue`, `set_issue_status`, `comment_on_issue`, and `list_comments`.
- **Output schemas.** Each output schema is a bundled copy of a file in [schemas/](schemas/): the root with `type: "object"`, `properties`, and `required` at the top level, plus a `$defs` section with every referenced definition, with each `$ref` rewritten to `#/$defs/<Name>`. The command `bin/console app:schema:bundle` generates `src/Mcp/BundledSchemas.php` with one `public const array` per root and `TOOLS`, the tools of `mcp-tools.json` with each output schema as its root constant. A test fails when the generated file differs from the output of the command.
- **Annotations.** All tools set `destructiveHint: false` and `openWorldHint: false`. The read tools set `readOnlyHint: true` and `idempotentHint: true`. The table in section 4 lists them per tool.
- **Domain errors.** The adapter catches `DomainError` and throws `ToolCallException(json_encode(['error' => ['code' => ..., 'message' => ..., 'details' => ...]]))`. The SDK turns that into `{"isError": true, "content": [{"type": "text", "text": "<Error JSON>"}]}`, with **no `structuredContent`**. So clients that validate `structuredContent` against the `outputSchema` do not reject the error.
- **Rate limits.** The adapter consumes the limiters of [README.md](README.md#rate-limits) before each tool runs, so every tool can return `rate_limited`. The tool lists below do not repeat it.
- **Other exceptions.** The adapter catches every other `Throwable`, logs it, and throws `ToolCallException` with code `internal_error`. The agent therefore never gets a bare -32603.
- **Protocol-level errors.** The SDK answers these before the tool runs. An unknown tool gives JSON-RPC error -32602 without `data`. Arguments that violate `inputSchema` give -32602 with `data.validation_errors`. Any `inputSchema` keyword can fail, for example `pattern`, `minLength`, `maximum`, `enum`, `type`, or a missing required field. Semantic checks give `isError` with `invalid_input`, for example a body of whitespace only, or a reason shorter than 20 characters after trimming.
- **Patterns.** Each input `pattern` equals the pattern of its definition in [common.schema.json](schemas/common.schema.json): `Ulid`, `CommitSha`, `CommitShaPrefix`, `PullRequestUrl`, `ProjectKey`, `IssueKey`, or `NoNulText` for every other string. PostgreSQL cannot store a NUL byte. A test checks each pattern.

## 4. Tools

The title, the description, the input schema, and the annotations of each tool are in [mcp-tools.json](mcp-tools.json). The table and the sections below give the output, the behaviour, the errors, and an example.

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
| [`set_request_title`](#418-set_request_title) | Set request title | false | false | [`request-command-result.schema.json`](schemas/request-command-result.schema.json) | [Q23](rest-api-requests.md#q23-set-the-title) |
| [`list_projects`](#419-list_projects) | List projects | true | true | [`lists.schema.json#/$defs/ProjectPage`](schemas/lists.schema.json) | [P1](rest-api-issues.md#p1-list-projects) |
| [`list_issues`](#420-list_issues) | List issues | true | true | [`lists.schema.json#/$defs/IssuePage`](schemas/lists.schema.json) | [I1](rest-api-issues.md#i1-list-issues) |
| [`get_issue`](#421-get_issue) | Get issue | true | true | [`issue.schema.json#/$defs/IssueDetail`](schemas/issue.schema.json) | [I2](rest-api-issues.md#i2-get-an-issue) |
| [`create_issue`](#422-create_issue) | Create issue | false | false | [`issue.schema.json`](schemas/issue.schema.json) | [I6](rest-api-issues.md#i6-create-an-issue) |
| [`update_issue`](#423-update_issue) | Update issue | false | true | [`issue.schema.json`](schemas/issue.schema.json) | [I7](rest-api-issues.md#i7-change-an-issue) |
| [`set_issue_status`](#424-set_issue_status) | Set issue status | false | false | [`issue.schema.json`](schemas/issue.schema.json) | [I8](rest-api-issues.md#i8-set-the-status) |
| [`comment_on_issue`](#425-comment_on_issue) | Comment on issue | false | false | [`issue.schema.json`](schemas/issue.schema.json) | [I3](rest-api-issues.md#i3-comment-on-an-issue) |
| [`link_issues`](#426-link_issues) | Link issues | false | false | [`issue.schema.json`](schemas/issue.schema.json) | none yet |
| [`approve_plan`](#427-approve_plan) | Approve plan | false | false | [`issue.schema.json`](schemas/issue.schema.json) | none. [I4](rest-api-issues.md#i4-approve-the-plan) is the approval of a user. |
| [`list_comments`](#428-list_comments) | List comments | true | true | [`lists.schema.json#/$defs/IssueCommentPage`](schemas/lists.schema.json) | [I5](rest-api-issues.md#i5-list-the-comments-of-a-project) |

Every tool sets `destructiveHint: false` and `openWorldHint: false`.

### 4.1 `list_repositories`

- **Output:** [`lists.schema.json#/$defs/RepositoryPage`](schemas/lists.schema.json).
- **Behaviour:** as REST R1 without the `name` and `source` filters.
- **Errors:** `invalid_input` for the cursor.
- **Example:** arguments `{}`. `structuredContent`: [fixtures/lists.repository-page.json](fixtures/lists.repository-page.json).

### 4.2 `list_reviews`

- **Output:** [`lists.schema.json#/$defs/ReviewPage`](schemas/lists.schema.json).
- **Behaviour:** `repository` is resolved as in [README.md section 7](README.md#7-repository-sources-names-and-commit-availability). Otherwise as REST V2 with `state=open`, or `state=all` when `includeClosed` is true.
- **Errors:** `not_found` for the repository, `invalid_input`.
- **Example:** arguments `{"repository":"acme/billing"}`. `structuredContent`: [fixtures/lists.review-page.json](fixtures/lists.review-page.json).

### 4.3 `list_requests`

- **Output:** [`lists.schema.json#/$defs/RequestPage`](schemas/lists.schema.json).
- **Behaviour:** REST Q1 with `sort=created`. `group` returns the requests of one group, which are one unit of feedback (D103): server instructions step 7.
- **Errors:** `not_found`, `invalid_input` for a cursor sent with other filters.
- **Example:** arguments `{"review":"01K6B3A1B2C3D4E5F6G7H8J9KM"}`. `structuredContent`: [fixtures/lists.request-page.json](fixtures/lists.request-page.json).

### 4.4 `get_updates`

- **Output:** [`updates-page.schema.json`](schemas/updates-page.schema.json).
- **Behaviour:** the algorithm of [rest-api-requests.md section 4](rest-api-requests.md#4-updates) with `includeOwn=false`.
- **Errors:** `not_found`, `invalid_input` for a cursor ahead of the server.
- **Example:** arguments `{"review":"01K6B3A1B2C3D4E5F6G7H8J9KM"}` give [fixtures/updates-page.first-call.json](fixtures/updates-page.first-call.json). Arguments `{"review":"01K6B3A1B2C3D4E5F6G7H8J9KM","cursor":1842}` give [fixtures/updates-page.cursor.json](fixtures/updates-page.cursor.json).

### 4.5 `get_request`

- **Output:** [`request.schema.json`](schemas/request.schema.json).
- **Behaviour:** REST Q3. `pullRequests` holds the state and the title of each linked pull request. `delivery` is the effective delivery choice of D85, `capabilities` says what the host offers, `type` says what the user asks for (D102): server instructions step 6, and `group` names the unit of feedback of the request (D103): server instructions step 7. `anchor.snapshot` holds the code text rebuilt from the blob, or null text fields when the rebuild fails (D113). On a `browser` repository `anchor.snapshot` and `excerpt` are null and `context` is ignored (D114): the agent reads the file at the commit of the anchor.
- **Errors:** `not_found`, `invalid_input` for an ambiguous prefix, `commit_not_found`, `repository_unavailable`. On `pass_through` an ambiguous prefix gives `commit_not_found`, because GitHub answers it as a missing commit.
- **Example:** arguments `{"id":"01K6B3P2A9QRSTVWXYZ0123456","at":"a1b2c3d4e5f60718293a4b5c6d7e8f9012345678"}` give [fixtures/request.in-progress.json](fixtures/request.in-progress.json). Arguments `{"id":"01K6B3P2A9QRSTVWXYZ0123457","context":2}` give [fixtures/request.with-excerpt.json](fixtures/request.with-excerpt.json).

### 4.6 `claim_request`

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q5.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`.
- **Example:** arguments `{"id":"01K6B3P2A9QRSTVWXYZ0123456"}` give [fixtures/request-command-result.claim.json](fixtures/request-command-result.claim.json). The error when another agent holds the request: [fixtures/error.invalid-transition.json](fixtures/error.invalid-transition.json).

### 4.7 `release_request`

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q6.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`.
- **Example:** arguments `{"id":"01K6B3P2A9QRSTVWXYZ0123457","note":"The fix needs a schema change. I stop here."}` give [fixtures/request-command-result.release.json](fixtures/request-command-result.release.json).

### 4.8 `reply_to_request`

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q4.
- **Errors:** `not_found`, `review_closed`, `invalid_input` for a body of whitespace only without questions, or a question that breaks a rule of D107.
- **Questions:** D107. Each item of `questions` is a question in one line and an optional Markdown `detail`. The comment is a question, also without `question: true`. The web UI shows the questions above the reply box with "Yes", "No", and a text field. The user's answer is a `request.commented` entry whose `questions` repeat each question with its `answer`, and whose body can be empty.
- **Example:** arguments ``{"id":"01K6B3P2A9QRSTVWXYZ0123457","body":"Two questions before I change the shipping totals.","questions":[{"question":"Should `TaxRounding` also cover the shipping totals?","detail":"The shipping lines round per line today. `TaxRounding` rounds once per invoice."},{"question":"Keep the old `round()` helper as deprecated?"}]}`` give [fixtures/request-command-result.question.json](fixtures/request-command-result.question.json).

### 4.9 `link_commits`

- **Output:** [`link-commits-result.schema.json`](schemas/link-commits-result.schema.json).
- **Behaviour:** REST Q7. One commit, 1 to 50 request IDs of one review, full SHA only. Linking an existing pair again is a no-op.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`, `invalid_input` for requests from several reviews.
- **Example:** arguments `{"commitSha":"a1b2c3d4e5f60718293a4b5c6d7e8f9012345678","requestIds":["01K6B3P2A9QRSTVWXYZ0123456","01K6B3P2A9QRSTVWXYZ0123457"],"note":"One refactoring covers both."}` give [fixtures/link-commits-result.available.json](fixtures/link-commits-result.available.json). An unpushed commit gives [fixtures/link-commits-result.pending.json](fixtures/link-commits-result.pending.json).

### 4.10 `unlink_commit`

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q8.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`, `invalid_input` when the commit is not linked.
- **Example:** arguments `{"commitSha":"e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e","requestId":"01K6B3P2A9QRSTVWXYZ0123457"}`. The result has the shape of [fixtures/request-command-result.claim.json](fixtures/request-command-result.claim.json) with one `commit.unlinked` event.

### 4.11 `mark_ready_for_review`

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q10.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`, `commits_not_available`, `repository_unavailable`, `invalid_input`.
- **Example:** arguments `{"id":"01K6B3P2A9QRSTVWXYZ0123456","note":"..."}` give [fixtures/request-command-result.ready.json](fixtures/request-command-result.ready.json). A pending link gives [fixtures/error.commits-not-available.json](fixtures/error.commits-not-available.json).

### 4.12 `mark_wont_do`

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q11.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`, `invalid_input` for fewer than 20 characters after trimming.
- **Example:** arguments as in [fixtures/inputs.wont-do.json](fixtures/inputs.wont-do.json) plus `id`. The result has the shape of [fixtures/request-command-result.ready.json](fixtures/request-command-result.ready.json) with status `wont_do`, `awaiting: user`, and one `request.wont_do` event.

### 4.13 `propose_solution`

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q14. The user approves with Q15, or replies to ask for changes. The approval has no tool.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`, `invalid_input` for a plan of whitespace only or a diff that is not a git diff of 1 to 9 files.
- **Example:** arguments as in [fixtures/inputs.propose-solution.json](fixtures/inputs.propose-solution.json) plus `id` give [fixtures/request-command-result.proposed.json](fixtures/request-command-result.proposed.json).

### 4.14 `link_pull_request`

- **Output:** [`link-pull-request-result.schema.json`](schemas/link-pull-request-result.schema.json).
- **Behaviour:** REST Q17. One URL, 1 to 50 request IDs of one review. Linking an existing pair again is a no-op; for a GitHub repository the number identifies the pull request. The link changes neither the status nor `awaiting`. For a GitHub repository, the server then reads the state of the pull request for `get_request`.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`, `invalid_input` for requests from several reviews, a pull request of another GitHub repository, or a review of a local repository, which has no pull requests.
- **Example:** arguments as in [fixtures/inputs.link-pull-request.json](fixtures/inputs.link-pull-request.json) give [fixtures/link-pull-request-result.linked.json](fixtures/link-pull-request-result.linked.json).

### 4.15 `unlink_pull_request`

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q19. For a GitHub repository the number identifies the pull request, and a linked URL with an earlier name of the repository is accepted too. The unlink changes neither the status nor `awaiting`.
- **Errors:** `not_found`, `review_closed`, `invalid_transition`, `invalid_input` when the URL breaks the rule of Q17 and is not a linked URL, or is not linked to the request.
- **Example:** arguments `{"url":"https://github.com/acme/billing/pull/42","requestId":"01K6B3P2A9QRSTVWXYZ0123457"}`. The result has the shape of [fixtures/request-command-result.claim.json](fixtures/request-command-result.claim.json) with one `pull_request.unlinked` event.

### 4.16 `start_review`

- **Output:** [`review.schema.json`](schemas/review.schema.json).
- **Behaviour:** `repository` is resolved as in [README.md section 7](README.md#7-repository-sources-names-and-commit-availability). Otherwise as REST V1 with the body `{ref, title}`. `startedBy` is the agent. Only the user closes the review.
- **Errors:** `not_found` for the repository, `invalid_input` for a malformed ref or an ambiguous SHA prefix, `commit_not_found`, `repository_unavailable`, `limit_exceeded` for `open_reviews`. On `pass_through` an ambiguous SHA prefix gives `commit_not_found`, because GitHub answers it as a missing commit.
- **Example:** arguments `{"repository":"acme/billing","ref":"main"}`. The result has the shape of [fixtures/review.open.json](fixtures/review.open.json) with the agent in `startedBy`.

### 4.17 `suggest_request`

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q20 with `title` and `body`. The flat parameters form the `anchor` of `SuggestRequestInput`: `anchorKind` is `kind`, `path` is `path`, `startLine` and `endLine` form `range`, and `symbol` is `symbol.fqn`. They go to the same anchor capture as Q20, which checks that the kind and the parameters agree. With `anchorKind` null, the suggestion is general, and `path`, `startLine`, `endLine`, and `symbol` must be null.
- **Errors:** `not_found`, `review_closed`, `repository_unavailable`, and `invalid_input` for the anchor errors of Q2, for example parameters that do not agree with `anchorKind`, for a title or a body of whitespace only, for a title with a line break, or for a 501st pending suggestion in the review, and `limit_exceeded` for `requests_per_review`.
- **Example:** arguments `{"review":"01K6B3A1B2C3D4E5F6G7H8J9KM","anchorKind":"line","path":"src/Billing/InvoiceTotals.php","startLine":55,"endLine":55}` plus the `title` and the `body` of [fixtures/inputs.suggest-request.json](fixtures/inputs.suggest-request.json) give [fixtures/request-command-result.suggested.json](fixtures/request-command-result.suggested.json).

### 4.18 `set_request_title`

- **Output:** [`request-command-result.schema.json`](schemas/request-command-result.schema.json).
- **Behaviour:** REST Q23 with the body `{title}`. An agent sets the title only while `title` is null, in any status. The title changes neither the status nor `awaiting`.
- **Errors:** `not_found`, `review_closed`, `invalid_transition` when the request has a title, `invalid_input` for a title of whitespace only or with a line break.
- **Example:** arguments `{"id":"01K6B3P2A9QRSTVWXYZ012345C"}` plus the `title` of [fixtures/inputs.title.json](fixtures/inputs.title.json) give [fixtures/request-command-result.retitled.json](fixtures/request-command-result.retitled.json).

### Issue tools

Sections 4.19 to 4.28 are the issue tracker (D116 to D118, D121, D122). A project has issues, and a repository belongs to at most one project. An issue is named by its key, `<project key>-<number>`, for example `SM-12`, and the number counts per project. The tools take the keys `project`, `issue`, and `target`, not ULIDs. The REST endpoints of [rest-api-issues.md](rest-api-issues.md) share the model and the rules of this section.

- **Visibility.** A token of one repository sees only the project of that repository, with its issues. A project without a repository is invisible to it. Any other key gets `not_found`.
- **Sections.** An issue has the Markdown sections `problem`, `plan`, `result`, and `followUps`, each null or at most 65,536 characters.
- **Thread.** Each write that changes the issue appends its entry in the same transaction and sets `updatedAt`. The entry kinds are `comment`, `status_changed`, `section_changed`, and `plan_approved`. A `section_changed` entry stores the new text, and `get_issue` returns its section without the text. The plan revision of an issue is the ID of the newest `section_changed` entry of `plan`.
- **Labels.** A `comment` entry has a `label` of the closed set `CommentLabel` of [common.schema.json](schemas/common.schema.json), which says what the comment is: `comment`, `decision`, `question`, `finding`, `deviation`, `scope_creep`, `papercut`, or `review`. A comment stored without a label reads as `comment`. An entry of another kind has `label` null (D121).
- **Approval.** `planApproval` holds the approval of the plan of `planRevision`. A `plan` with new text clears it. The same text changes nothing and writes no entry. An approval of an agent has a `reviewer` and a `reviewNote`. An approval of a user, REST [I4](rest-api-issues.md#i4-approve-the-plan), has both null.
- **Rules.** The server checks the state that a write gives (D117). The content rules hold for every actor. The approval rules bind agents only, and the MCP actor is always an agent.

  | Status | What must hold | Field of the refusal |
  |---|---|---|
  | `backlog`, `todo`, `in_progress`, `in_review`, `done` | `problem` is not empty | `problem` |
  | `in_progress`, `in_review`, `done` | `plan` is not empty and approved as `approvalMode` says: `user`, by a user; `agent_review`, by a user or by an agent with a reviewer; `self`, no approval | `plan` |
  | `in_review`, `done` | `result` is not empty | `result` |
  | `done`, `wont_do`, set by an agent | `user`: refused. `agent_review`: `reviewer` and `note` given. `self`: allowed. | `status`, `reviewer`, `note` |
  | `wont_do` | `note` given | `note` |

  - A section of whitespace only is empty, and so are a `note` and a `reviewer`.
  - An agent cannot set the status that the issue has: `status`. `todo` to `in_progress` is the claim.
  - An agent changes no field and no status of an issue in `done` or `wont_do`: `issue`. It can comment on it and link it.
  - An agent cannot change `plan` in `in_progress` or `in_review`: `plan`. A deviation goes into `result`.
  - An approval by a user of an issue in `awaiting_user_input` sets `todo`, with a `status_changed` entry, when the issue has a `problem`. Without one the status stays.
  - A user, the session of REST [I6](rest-api-issues.md#i6-create-an-issue) to [I8](rest-api-issues.md#i8-set-the-status), is bound by the content rules only: `problem`, a `plan` with text, `result`, and the `note` of `wont_do`. A user sets every status, also from `done` and `wont_do`, and changes every field in every status. The status that the issue has is no error for a user and writes no entry.
  - After a user changes `plan` of an issue in `in_progress` or `in_review`, the approval is gone. In `user` and `agent_review`, each write of an agent on the issue is then refused, until the plan is approved again. The field is `plan`, unless another rule refuses the write first: in `user`, `status` for `done` and `wont_do`, and `issue` for `approve_plan`. Only a comment, a link, and a move to a status that needs no plan pass: `draft`, `backlog`, `todo`, `awaiting_user_input`, and `wont_do` as the table says.
- **Refusals.** A refusal of a rule is `isError` with `invalid_input`, and `details.violations[0]` has the field of the table or the bullet and the reason as its message. The issue tools never return `invalid_transition` or `review_closed`.

### 4.19 `list_projects`

- **Output:** [`lists.schema.json#/$defs/ProjectPage`](schemas/lists.schema.json).
- **Behaviour:** REST P1: the projects that the token sees, in key order, each with the names of its repositories that the token sees, in name order. `limit` is 1 to 500, by default 100.
- **Errors:** `invalid_input` for the cursor.
- **Example:** arguments `{}`. `structuredContent`: [fixtures/lists.project-page.json](fixtures/lists.project-page.json).

### 4.20 `list_issues`

- **Output:** [`lists.schema.json#/$defs/IssuePage`](schemas/lists.schema.json).
- **Behaviour:** REST I1: the summaries of the issues of the project, newest `updatedAt` first, then by ID. `status` returns only the issues with that status. `limit` is 1 to 200, by default 50.
- **Errors:** `not_found` for the project, `invalid_input` for the cursor or a cursor sent with another filter.
- **Example:** arguments `{"project":"BILL"}`. `structuredContent`: [fixtures/lists.issue-page.json](fixtures/lists.issue-page.json).

### 4.21 `get_issue`

- **Output:** [`issue.schema.json#/$defs/IssueDetail`](schemas/issue.schema.json).
- **Behaviour:** REST I2: the issue, its links, and its thread, each oldest first. A link shows the other issue. `allowedStatuses` holds the statuses that `set_issue_status` takes from the caller, with a note and a reviewer where the rules need them.
- **Errors:** `not_found`.
- **Example:** arguments `{"issue":"BILL-12"}`. `structuredContent`: [fixtures/issue.detail.json](fixtures/issue.detail.json).

### 4.22 `create_issue`

- **Output:** [`issue.schema.json`](schemas/issue.schema.json).
- **Behaviour:** REST I6: a new issue with the next number of the project, which the server takes under a lock on the project row. `status` is `draft`, `backlog`, or `todo`, by default `draft`. A `problem` writes a `section_changed` entry. The server stores the title trimmed.
- **Errors:** `not_found` for the project, `invalid_input` for a title of whitespace only or with a line break, or on `problem` for `backlog` or `todo` without a problem.
- **Example:** arguments `{"project":"BILL","title":"Round invoice totals once per invoice"}`. The result has the shape of [fixtures/issue.plan-approved.json](fixtures/issue.plan-approved.json) with status `draft` and the sections null.

### 4.23 `update_issue`

- **Output:** [`issue.schema.json`](schemas/issue.schema.json).
- **Behaviour:** REST I7: changes the given fields. Null leaves a field as it is. Each section with new text writes a `section_changed` entry, and a `plan` with new text clears `planApproval`. A call whose values equal the stored ones changes nothing.
- **Errors:** `not_found`, `invalid_input` for a call with no field, a title of whitespace only or with a line break, or a broken rule of the issue tools.
- **Example:** arguments `{"issue":"BILL-12","plan":"1. Add `TaxRounding::perInvoice()` ..."}`. The result has the shape of [fixtures/issue.plan-approved.json](fixtures/issue.plan-approved.json) with a new `planRevision` and `planApproval` null.

### 4.24 `set_issue_status`

- **Output:** [`issue.schema.json`](schemas/issue.schema.json).
- **Behaviour:** REST I8: moves the issue to `status` under the rules of the issue tools, and writes a `status_changed` entry with `from`, `to`, `note`, and `reviewer`. Any status can follow any other status when the rules hold.
- **Errors:** `not_found`, `invalid_input` for a broken rule.
- **Example:** arguments `{"issue":"BILL-12","status":"in_progress"}`. The result has the shape of [fixtures/issue.plan-approved.json](fixtures/issue.plan-approved.json) with status `in_progress`.

### 4.25 `comment_on_issue`

- **Output:** [`issue.schema.json`](schemas/issue.schema.json).
- **Behaviour:** REST I3 with the body `{body, label}`: appends a `comment` entry with the label, in any status. `label` is `comment` by default.
- **Errors:** `not_found`, `invalid_input` for a body of whitespace only. A `label` outside `CommentLabel` is JSON-RPC -32602.
- **Example:** arguments `{"issue":"BILL-12","label":"decision"}` plus the `body` of [fixtures/inputs.issue-comment.json](fixtures/inputs.issue-comment.json). The result has the shape of [fixtures/issue.plan-approved.json](fixtures/issue.plan-approved.json).

### 4.26 `link_issues`

- **Output:** [`issue.schema.json`](schemas/issue.schema.json): the issue of `issue`.
- **Behaviour:** one link per pair of issues, without a direction: `get_issue` of each issue shows it. The link sets `updatedAt` of both issues and writes no entry. The issues can belong to two projects.
- **Errors:** `not_found` for either issue, `invalid_input` on `target` for a link of an issue to itself or a second link of the pair, and on `note` for a note of whitespace only.
- **Example:** arguments `{"issue":"BILL-12","target":"BILL-9","note":"BILL-9 owns the shipping totals, which this issue leaves alone."}`. The result has the shape of [fixtures/issue.plan-approved.json](fixtures/issue.plan-approved.json).

### 4.27 `approve_plan`

- **Output:** [`issue.schema.json`](schemas/issue.schema.json).
- **Behaviour:** records the review of the plan by another agent: a `plan_approved` entry with `reviewer`, `reviewNote`, and `planRevision`, which becomes `planApproval`. The server cannot prove the review. A second approval replaces the first one. The approval of a user has no tool: REST [I4](rest-api-issues.md#i4-approve-the-plan).
- **Errors:** `not_found`, `invalid_input` on `issue` when `approvalMode` is `user` or the issue is in `done` or `wont_do`, on `plan` for an issue without a plan, on `planRevision` for a revision that is not the newest, on `reviewer` for whitespace only, and on `reviewNote` for fewer than 20 characters after trimming.
- **Example:** arguments `{"issue":"BILL-12","planRevision":"01K7F3C5D6E7F8G9H0J1K2M3N4","reviewer":"plan-reviewer"}` plus the `reviewNote` of the fixture give [fixtures/issue.plan-approved.json](fixtures/issue.plan-approved.json).

### 4.28 `list_comments`

- **Output:** [`lists.schema.json#/$defs/IssueCommentPage`](schemas/lists.schema.json).
- **Behaviour:** REST I5: the `comment` entries of every issue of the project, newest first by entry ID, each with the summary of its issue, its label, its actor, and its body. `label` returns only the comments with that label. `limit` is 1 to 200, by default 50.
- **Errors:** `not_found` for the project, `invalid_input` for the cursor or a cursor sent with another filter.
- **Example:** arguments `{"project":"BILL"}`. `structuredContent`: [fixtures/lists.issue-comment-page.json](fixtures/lists.issue-comment-page.json).

## 5. Error codes in MCP

| Code | Delivered as | Agent action |
|---|---|---|
| `unauthorized` | HTTP 401 on the transport, never a tool result | Tell the user to check `SLOPMOWER_TOKEN` |
| `not_found` | isError | Check the ID or name, then list again. Repositories outside the token scope also answer `not_found`. |
| `invalid_input` | isError. Schema violations come as JSON-RPC -32602. | Fix the input. From an issue tool: read `details.violations[0]`, which names the field that breaks a rule of the [issue tools](#issue-tools), fix the issue, then call again. |
| `invalid_transition` | isError, never from an issue tool | `get_request`, then read `allowedActions`, `proposal`, `suggestion`, `title`, and `details.claimedBy` |
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
| `set_request_title` | `POST /api/v1/requests/{id}/title` |
| `list_projects` | `GET /api/v1/projects` |
| `list_issues` | `GET /api/v1/projects/{projectKey}/issues?status=` |
| `get_issue` | `GET /api/v1/issues/{issueKey}` |
| `comment_on_issue` | `POST /api/v1/issues/{issueKey}/comments` |
| `list_comments` | `GET /api/v1/projects/{projectKey}/comments?label=` |
| `create_issue` | `POST /api/v1/projects/{projectKey}/issues` |
| `update_issue` | `PATCH /api/v1/issues/{issueKey}` |
| `set_issue_status` | `POST /api/v1/issues/{issueKey}/status` |
| `link_issues` | none yet |
| `approve_plan` | none. `POST /api/v1/issues/{issueKey}/plan-approval` is the approval of a user, for a session only. |

`McpRestParityTest` compares the MCP and REST payloads of every tool with a REST endpoint: `list_repositories`, `list_reviews`, `list_requests`, `get_updates`, `get_request`, every write tool of the requests and reviews, `list_projects`, `list_issues`, `get_issue`, `create_issue`, `update_issue`, `set_issue_status`, `comment_on_issue`, and `list_comments`.

## 7. Wire examples

Success. The request:

```json
{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"claim_request","arguments":{"id":"01K6B3P2A9QRSTVWXYZ0123456"}}}
```

The response. The members `"...": "..."` stand for the rest of [fixtures/request-command-result.claim.json](fixtures/request-command-result.claim.json). `content[0].text` holds the same data as `structuredContent`, as pretty-printed JSON. Tests compare the decoded JSON, not the bytes.

```json
{"jsonrpc":"2.0","id":4,"result":{
 "content":[{"type":"text","text":"{\"request\":{\"id\":\"01K6B3P2A9QRSTVWXYZ0123456\",\"status\":\"in_progress\",\"...\":\"...\"},\"allowedActions\":[\"reply\",\"release\",\"link_commits\",\"link_pull_request\",\"mark_ready_for_review\",\"mark_wont_do\",\"propose_solution\",\"set_title\"],\"events\":[{\"seq\":1840,\"type\":\"request.claimed\",\"...\":\"...\"}]}"}],
 "structuredContent":{"request":{"id":"01K6B3P2A9QRSTVWXYZ0123456","status":"in_progress","awaiting":"agent","...":"..."},"allowedActions":["reply","release","link_commits","link_pull_request","mark_ready_for_review","mark_wont_do","propose_solution","set_title"],"events":[{"seq":1840,"type":"request.claimed","...":"..."}]},
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
