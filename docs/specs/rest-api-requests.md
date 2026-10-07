# Holistic Review REST API v1: requests, drafts, and tokens

This file continues [rest-api.md](rest-api.md). That file has the conventions, the [endpoint index](rest-api.md#2-endpoint-index), and the common errors, which this file does not repeat. Request bodies are defined in [inputs.schema.json](schemas/inputs.schema.json).

## 1. Commands and the state machine

| Command | REST | MCP | Actor | From | To | Events | Repeated call |
|---|---|---|---|---|---|---|---|
| open | [Q2](#q2-open-a-request) | none | user | none | `open` | `request.opened` | Creates another request |
| carry over | [V15](rest-api.md#v15-carry-over-open-requests) | none | user | none | `open` | `request.opened` per carried request, then `request.commented` for its first comment, if any | `already_carried`, no event |
| reply | [Q4](#q4-comment-on-a-request) | `reply_to_request` | user, agent | any status | unchanged | `request.commented` | Adds another comment |
| claim | [Q5](#q5-claim-a-request) | `claim_request` | agent | `open`, `reopened` | `in_progress` | `request.claimed` | The same agent on its own `in_progress` request: 200, no event, `events: []` |
| release | [Q6](#q6-release-a-request) | `release_request` | the claiming agent, or the user with "Unassign" | `in_progress` | the status before the claim | `request.released` | 409 `invalid_transition` |
| link | [Q7](#q7-link-a-commit-to-requests) | `link_commits` | the claiming agent, for **every** named request | `in_progress` | unchanged | one `commit.linked` that names the newly linked requests | Pairs that are already linked are skipped. No event when nothing is new. |
| unlink | [Q8](#q8-unlink-a-commit) | `unlink_commit` | the claiming agent | `in_progress` | unchanged | `commit.unlinked` | 422 `invalid_input` when not linked |
| ready | [Q10](#q10-mark-ready-for-review) | `mark_ready_for_review` | the claiming agent | `in_progress` | `ready_for_review` | any `commit.found` first, then `request.ready_for_review` | 409 |
| won't do | [Q11](#q11-mark-wont-do) | `mark_wont_do` | agent, which must be the claiming agent when `in_progress`, or user | `open`, `reopened`, `in_progress` | `wont_do` | `request.wont_do` | 409 |
| verify | [Q12](#q12-verify-a-request) | none | user | `ready_for_review`; `wont_do` set by an agent | `verified` | `request.verified` | 409 |
| reopen | [Q13](#q13-reopen-a-request) | none | user | `ready_for_review`, `wont_do`, `verified` | `reopened` | `request.reopened` | 409 |

Rules:

- `claimedBy` and `claimedAt` are set only while the status is `in_progress`. Leaving `in_progress` clears them.
- Two actors are the same when kind, `userId`, and `agentName` match. So tokens with the same name act as the same agent. This allows token rotation.
- A user comment with `question: true` gets 422. Only agents ask questions.
- `awaiting` follows the rules 1 to 5 of [architecture.md](../architecture.md) section 6. The server evaluates them after each event.
- `closed` is true for `verified` and for `wont_do` set by the user.
- `resolution`:
  - `null` for `open`, `in_progress`, and `reopened`;
  - `fixed` for `ready_for_review`, and for `verified` reached from `ready_for_review`;
  - `wont_do` for `wont_do`, and for `verified` reached from `wont_do`.
- Every command takes the tenant row lock and writes its events and projections in one transaction. After the commit, a command that wrote events publishes one live update. See [webhooks-and-live-updates.md](webhooks-and-live-updates.md#3-live-updates).
- A command on a closed review gets 409 `review_closed`. See [V4](rest-api.md#v4-close-a-review).

General requests: a request with `anchor: null` belongs to the whole review and names no file, line, or symbol. It has no capture, so `location` and `excerpt` are null, `outdated` stays null, and `touchesAnchor` is false. No file view shows it. Its lifecycle and `allowedActions` are the same as for a request with an anchor, and a `new_request` draft can be general.

`allowedActions` for the caller. It is empty when the review is closed.

| Action | Agent caller | User caller |
|---|---|---|
| `reply` | always | always |
| `claim` | status `open` or `reopened` | never |
| `release` | `in_progress` and claimed by the caller | `in_progress` |
| `link_commits` | `in_progress` and claimed by the caller | never |
| `unlink_commit` | as `link_commits`, and at least one link exists | never |
| `mark_ready_for_review` | `in_progress` and claimed by the caller | never |
| `mark_wont_do` | `open` or `reopened`, or `in_progress` claimed by the caller | `open`, `reopened`, `in_progress` |
| `verify` | never | `ready_for_review`, or `wont_do` whose last `request.wont_do` actor is an agent |
| `reopen` | never | `ready_for_review`, `wont_do`, `verified` |

## 2. Queries

### Q1 List requests

- **Request:** `GET /api/v1/reviews/{reviewId}/requests`
- **Authentication:** S, B
- **Success:** 200 [`RequestPage`](schemas/lists.schema.json). `total` counts all matches, independent of the cursor. Closed reviews can be listed.
- **Errors:** 422 `invalid_input` for a cursor sent with filters other than those of the page that returned it.

| Query | Type | Default | Notes |
|---|---|---|---|
| `status` | list of RequestStatus | all | |
| `awaiting` | list of Awaiting | all | "Waiting for you" is `awaiting=user` |
| `path` | string of at most 4096 characters, no NUL, no leading `/` | none | Prefix of `anchor.path` at the review commit. Never matches a general request. |
| `outdated` | boolean | none | Filters on `RequestSummary.outdated`. Null values never match. |
| `sort` | `created`\|`updated`\|`path` | `created` | `created`: `id` ascending. `updated`: `updatedAt` descending, then `id`. `path`: general requests first, then `anchor.path`, then `anchor.range.startLine` with file anchors first, then `id`. |
| `limit` | integer 1-200 | 50 | |
| `cursor` | Cursor | none | |

Example response, [fixtures/lists.request-page.json](fixtures/lists.request-page.json):

```json
{
  "reviewId": "01K6B3A1B2C3D4E5F6G7H8J9KM",
  "reviewState": "open",
  "total": 2,
  "nextCursor": null,
  "items": [
    {"id":"01K6B3P2A9QRSTVWXYZ0123456","reviewId":"01K6B3A1B2C3D4E5F6G7H8J9KM","repositoryId":"01K6B2Z0R3P0S1T2V3W4X5Y6Z7","status":"open","awaiting":"agent","resolution":null,"closed":false,"anchor":{"kind":"symbol","path":"src/Billing/InvoiceTotals.php","range":{"startLine":38,"endLine":49},"symbol":{"language":"php","kind":"method","fqn":"App\\Billing\\InvoiceTotals::total()"}},"summary":"Use `Money` instead of `float` for totals.","author":{"kind":"user","userId":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","agentName":null,"name":"janklan","avatarUrl":"https://avatars.githubusercontent.com/u/1?v=4"},"claimedBy":null,"claimedAt":null,"commentCount":0,"commitCount":0,"pendingCommitCount":0,"outdated":false,"createdAt":"2026-09-29T07:20:00Z","updatedAt":"2026-09-29T07:20:00Z","lastEventSeq":1830,"carriedFrom":null},
    {"id":"01K6B3P2A9QRSTVWXYZ0123457","reviewId":"01K6B3A1B2C3D4E5F6G7H8J9KM","repositoryId":"01K6B2Z0R3P0S1T2V3W4X5Y6Z7","status":"open","awaiting":"agent","resolution":null,"closed":false,"anchor":{"kind":"range","path":"src/Billing/InvoiceTotals.php","range":{"startLine":52,"endLine":58},"symbol":null},"summary":"Extract the tax rounding into `TaxRounding`.","author":{"kind":"user","userId":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","agentName":null,"name":"janklan","avatarUrl":"https://avatars.githubusercontent.com/u/1?v=4"},"claimedBy":null,"claimedAt":null,"commentCount":1,"commitCount":0,"pendingCommitCount":0,"outdated":false,"createdAt":"2026-09-29T07:10:00Z","updatedAt":"2026-09-29T07:20:00Z","lastEventSeq":1831,"carriedFrom":null}
  ]
}
```

### Q3 Get a request

- **Request:** `GET /api/v1/requests/{requestId}`
- **Authentication:** S, B
- **Success:** 200 [`Request`](schemas/request.schema.json)
- **Errors:**
  - 422 `invalid_input`: an ambiguous prefix, or `context` out of range;
  - 422 `commit_not_found`;
  - 409 `repository_unavailable`: a fetch was needed and failed, or the clone is gone and `at` was given.

| Query | Type | Default | Notes |
|---|---|---|---|
| `at` | `^[0-9a-f]{7,64}$` | none | Resolved with `git rev-parse --verify --end-of-options <at>^{commit}`. If the commit is missing, the server fetches once. With `at`, `location.target` is `requested`. |
| `context` | integer 0-50 | none | When set, `excerpt` holds the located region at the target commit, with `context` lines on each side and at most 400 lines. `excerpt` is null when the location is `orphaned`, or the file is not renderable, not valid UTF-8, or empty. |

- Without `at`, the server computes `location` at the default-branch head, with `target: default_branch`. `location` is null when the clone is gone.
- A general request has `location` and `excerpt` null. The server checks only the syntax of `at` and `context`, so it never resolves `at` and never sends `commit_not_found` or `repository_unavailable` for it. Example: [fixtures/request.general.json](fixtures/request.general.json).
- `thread` holds, in `seq` order:
  - the events of the request;
  - `commit.linked` events whose `requestIds` contain it;
  - `commit.found` events for commits linked to it.
- `commits` is sorted by `linkedAt`.
- `carriedFrom` names the source request of a request that [V15](rest-api.md#v15-carry-over-open-requests) carried over, else it is null. Example: [fixtures/request.carried.json](fixtures/request.carried.json).

Example response for `GET /api/v1/requests/01K6B3P2A9QRSTVWXYZ0123456?at=a1b2c3d4e5f60718293a4b5c6d7e8f9012345678`, [fixtures/request.in-progress.json](fixtures/request.in-progress.json):

```json
{
  "id": "01K6B3P2A9QRSTVWXYZ0123456",
  "reviewId": "01K6B3A1B2C3D4E5F6G7H8J9KM",
  "repositoryId": "01K6B2Z0R3P0S1T2V3W4X5Y6Z7",
  "status": "in_progress",
  "awaiting": "agent",
  "resolution": null,
  "closed": false,
  "anchor": {"kind":"symbol","path":"src/Billing/InvoiceTotals.php","commitSha":"79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091","blobSha":"3b18e512dba79e4c8300dd08aeb37f8e728b8dad","range":{"startLine":38,"endLine":49},"symbol":{"language":"php","kind":"method","fqn":"App\\Billing\\InvoiceTotals::total()"},"snapshot":{"lines":["    /**","     * Sum of all invoice lines.","     */","    public function total(): float","    {","        $sum = 0.0;","        foreach ($this->lines as $line) {","            $sum += $line->amount;","        }","","        return $sum;","    }"],"truncated":false,"lineCount":12,"before":["    }","",""],"after":["","    public function tax(): float","    {"],"normalizedHash":"sha256:4cd0f94b186ef4903963fa0397ae6175cd9f82d31d4512d8e9b67a2b3a0142f9"}},
  "summary": "Use `Money` instead of `float` for totals.",
  "author": {"kind":"user","userId":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","agentName":null,"name":"janklan","avatarUrl":"https://avatars.githubusercontent.com/u/1?v=4"},
  "claimedBy": {"kind":"agent","userId":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","agentName":"claude-code laptop","name":"claude-code laptop","avatarUrl":null},
  "claimedAt": "2026-09-29T07:40:00Z",
  "commentCount": 0,
  "commitCount": 1,
  "pendingCommitCount": 0,
  "outdated": false,
  "createdAt": "2026-09-29T07:20:00Z",
  "updatedAt": "2026-09-29T07:45:00Z",
  "lastEventSeq": 1842,
  "carriedFrom": null,
  "body": "Use `Money` instead of `float` for totals.",
  "reviewState": "open",
  "reviewCommitSha": "79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091",
  "location": {"target":"requested","targetSha":"a1b2c3d4e5f60718293a4b5c6d7e8f9012345678","status":"changed","path":"src/Billing/InvoiceTotals.php","range":{"startLine":38,"endLine":51},"confidence":1,"reason":null},
  "excerpt": null,
  "thread": [
    {"seq":1830,"eventId":"01K6C2EVT00000000000001830","type":"request.opened","actor":{"kind":"user","userId":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","agentName":null,"name":"janklan","avatarUrl":"https://avatars.githubusercontent.com/u/1?v=4"},"occurredAt":"2026-09-29T07:20:00Z","body":"Use `Money` instead of `float` for totals.","commentId":null,"question":false,"batchId":"01K6B3P1Z8Y7X6W5V4T3S2R1Q0","commitSha":null,"statusAfter":"open"},
    {"seq":1840,"eventId":"01K6C2EVT00000000000001840","type":"request.claimed","actor":{"kind":"agent","userId":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","agentName":"claude-code laptop","name":"claude-code laptop","avatarUrl":null},"occurredAt":"2026-09-29T07:40:00Z","body":null,"commentId":null,"question":false,"batchId":null,"commitSha":null,"statusAfter":"in_progress"},
    {"seq":1842,"eventId":"01K6C2EVT00000000000001842","type":"commit.linked","actor":{"kind":"agent","userId":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","agentName":"claude-code laptop","name":"claude-code laptop","avatarUrl":null},"occurredAt":"2026-09-29T07:45:00Z","body":"One refactoring covers both.","commentId":null,"question":false,"batchId":null,"commitSha":"a1b2c3d4e5f60718293a4b5c6d7e8f9012345678","statusAfter":"in_progress"}
  ],
  "commits": [
    {"commitSha":"a1b2c3d4e5f60718293a4b5c6d7e8f9012345678","state":"available","linkedBy":{"kind":"agent","userId":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","agentName":"claude-code laptop","name":"claude-code laptop","avatarUrl":null},"linkedAt":"2026-09-29T07:45:00Z","note":"One refactoring covers both.","foundAt":"2026-09-29T07:45:00Z","subject":"Use Money for invoice totals (01K6B3P2A9QRSTVWXYZ0123456)","committedAt":"2026-09-29T07:44:10Z","otherRequestIds":["01K6B3P2A9QRSTVWXYZ0123457"]}
  ],
  "allowedActions": ["reply","release","link_commits","unlink_commit","mark_ready_for_review","mark_wont_do"]
}
```

Another request, read by the claiming agent with `context=2` and without `at`: [fixtures/request.with-excerpt.json](fixtures/request.with-excerpt.json).

### Q9 Get a commit diff

- **Request:** `GET /api/v1/requests/{requestId}/commits/{commitSha}`
- **Authentication:** S, B
- **Success:** 200 [`CommitDiff`](schemas/commit-diff.schema.json)
- **Errors:**
  - 404 `not_found`: the commit is not linked to this request;
  - 409 `commits_not_available`: the link is pending;
  - 409 `repository_unavailable`.

The endpoint returns the diff data of one linked commit for the verify view.

- It compares the commit with its first parent: `git diff-tree -r -M --numstat` plus the raw output. It lists at most 300 files. `truncated` is true when more files changed.
- `location` is the request located at this commit, with `target: linked_commit`.
- `touchesAnchor` is true when `path` equals `location.path`. When `location` is null or orphaned, it is true when `path` or `oldPath` equals the anchor path.
- For a general request, `location` is null and `touchesAnchor` is false.
- The UI loads each side through [R7](rest-api.md#r7-get-a-blob) with `oldBlobSha` and `newBlobSha`, and renders `@pierre/diffs` `FileDiff`.

Example response, [fixtures/commit-diff.modified.json](fixtures/commit-diff.modified.json):

```json
{
  "requestId": "01K6B3P2A9QRSTVWXYZ0123456",
  "commitSha": "a1b2c3d4e5f60718293a4b5c6d7e8f9012345678",
  "parentSha": "79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091",
  "subject": "Use Money for invoice totals (01K6B3P2A9QRSTVWXYZ0123456)",
  "message": "Use Money for invoice totals (01K6B3P2A9QRSTVWXYZ0123456)\n",
  "authorName": "Jan Klan",
  "authorEmail": "jan@example.com",
  "committedAt": "2026-09-29T07:44:10Z",
  "location": {"target":"linked_commit","targetSha":"a1b2c3d4e5f60718293a4b5c6d7e8f9012345678","status":"changed","path":"src/Billing/InvoiceTotals.php","range":{"startLine":38,"endLine":51},"confidence":1,"reason":null},
  "files": [
    {"status":"modified","path":"src/Billing/InvoiceTotals.php","oldPath":null,"oldBlobSha":"3b18e512dba79e4c8300dd08aeb37f8e728b8dad","newBlobSha":"9c2e4a1f0b3d5e7f8091a2b3c4d5e6f708192a3b","oldMode":"100644","newMode":"100644","additions":6,"deletions":4,"binary":false,"tooLarge":false,"touchesAnchor":true},
    {"status":"added","path":"tests/Billing/InvoiceTotalsTest.php","oldPath":null,"oldBlobSha":null,"newBlobSha":"d4e5f60718293a4b5c6d7e8f9012345678a1b2c3","oldMode":null,"newMode":"100644","additions":41,"deletions":0,"binary":false,"tooLarge":false,"touchesAnchor":false}
  ],
  "truncated": false
}
```

## 3. Writes

All command responses except Q2 and Q7 are [`RequestCommandResult`](schemas/request-command-result.schema.json): `{request: RequestSummary, allowedActions, events}`. `events` holds the events that the command wrote.

### Q2 Open a request

- **Request:** `POST /api/v1/reviews/{reviewId}/requests`
- **Authentication:** S
- **Body:** [`OpenRequestInput`](schemas/inputs.schema.json), `{anchor?: AnchorInput|null, body}`.
- **Success:** 201 [`Request`](schemas/request.schema.json) in full, with `Location: /api/v1/requests/{id}`. Examples: [fixtures/request.opened-range.json](fixtures/request.opened-range.json), [fixtures/request.general.json](fixtures/request.general.json).
- **Errors:**
  - 422 `invalid_input`: unknown path, range outside the file, FQN not found or ambiguous, kind and fields do not agree, or the file is not renderable or not valid UTF-8 for a `line`, `range`, or `symbol` anchor;
  - 409 `review_closed`;
  - 409 `repository_unavailable`: the clone is gone, for a request with an anchor.

The endpoint opens a request at once, as a "single comment". Anchor capture: the server reads the blob at the review commit and captures the snapshot, as [architecture.md](../architecture.md) section 5.1 describes. An absent or null `anchor` opens a general request: there is no capture, so no anchor error and no `repository_unavailable`.

- A `symbol` anchor looks up `symbol.fqn` in the symbol table of the file. It must match exactly one symbol. The range of that symbol, including the doc comment, becomes `anchor.range`.
- `line` requires `startLine == endLine`.
- `range` requires `endLine > startLine`.
- `file` works for any file, including binary and too-large files.
- `line`, `range`, and `symbol` require a renderable file in valid UTF-8.

Example body, [fixtures/inputs.open-request.json](fixtures/inputs.open-request.json):

```json
{"anchor":{"kind":"range","path":"src/Billing/InvoiceTotals.php","range":{"startLine":52,"endLine":58}},"body":"Extract the tax rounding into `TaxRounding`."}
```

Example body of a general request, [fixtures/inputs.open-general-request.json](fixtures/inputs.open-general-request.json):

```json
{"anchor":null,"body":"Add a full-screen focus mode.\n\nIt hides the file tree and the request list, so the code uses the whole window."}
```

### Q4 Comment on a request

- **Request:** `POST /api/v1/requests/{requestId}/comments`
- **Authentication:** S, B
- **Body:** [`CommentInput`](schemas/inputs.schema.json), `{body, question?}`. `question` defaults to false. Only agents may set it to true. Example: [fixtures/inputs.comment.json](fixtures/inputs.comment.json).
- **Success:** 201 `RequestCommandResult`. Example: [fixtures/request-command-result.question.json](fixtures/request-command-result.question.json).
- **Errors:** 422 `invalid_input`, 409 `review_closed`.

### Q5 Claim a request

- **Request:** `POST /api/v1/requests/{requestId}/claim`
- **Authentication:** B
- **Body:** none.
- **Success:** 200 `RequestCommandResult`. Example: [fixtures/request-command-result.claim.json](fixtures/request-command-result.claim.json).
- **Errors:**
  - 409 `invalid_transition`: the status does not allow a claim, or another agent holds the request. `details.claimedBy` names that agent.
  - 409 `review_closed`.

Example error, [fixtures/error.invalid-transition.json](fixtures/error.invalid-transition.json):

```json
{"error":{"code":"invalid_transition","message":"Request 01K6B3P2A9QRSTVWXYZ0123456 is in progress by another agent.","details":{"requestId":"01K6B3P2A9QRSTVWXYZ0123456","action":"claim","status":"in_progress","claimedBy":{"kind":"agent","userId":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","agentName":"codex desktop","name":"codex desktop","avatarUrl":null},"allowedActions":["reply"]}}}
```

### Q6 Release a request

- **Request:** `POST /api/v1/requests/{requestId}/release`
- **Authentication:** S, B
- **Body:** [`OptionalNoteInput`](schemas/inputs.schema.json), `{note?}`. Example: [fixtures/inputs.release.json](fixtures/inputs.release.json).
- **Success:** 200 `RequestCommandResult`. Example: [fixtures/request-command-result.release.json](fixtures/request-command-result.release.json).
- **Errors:** 409 `invalid_transition`, 409 `review_closed`.

### Q7 Link a commit to requests

- **Request:** `POST /api/v1/commit-links`
- **Authentication:** B
- **Body:** [`LinkCommitsInput`](schemas/inputs.schema.json), `{commitSha, requestIds, note?}`.
  - `commitSha` is a full SHA.
  - `requestIds` holds 1 to 50 unique IDs, all from the same review.
- **Success:** 200 [`LinkCommitsResult`](schemas/link-commits-result.schema.json). `state` is the state of the commit, which is the same for all its links. Examples: [fixtures/link-commits-result.available.json](fixtures/link-commits-result.available.json), [fixtures/link-commits-result.pending.json](fixtures/link-commits-result.pending.json).
- **Errors:**
  - 404 `not_found`: any request ID;
  - 422 `invalid_input`: requests from more than one review, or a malformed SHA;
  - 409 `review_closed`;
  - 409 `invalid_transition`: the first request that is not `in_progress` and claimed by the caller. Nothing is written.

Availability check: the server checks the clone with `git cat-file -e`, without a fetch.

- If the commit is present, the link is `available`, `foundAt` equals the link time, and the server writes the pin ref `refs/holistic-review/commits/<sha>`.
- Otherwise the link is `pending`, and the server queues an asynchronous fetch. It does this always for local sources, and for GitHub sources as a fallback to the push webhook.

Example body, [fixtures/inputs.link-commits.json](fixtures/inputs.link-commits.json):

```json
{"commitSha":"a1b2c3d4e5f60718293a4b5c6d7e8f9012345678","requestIds":["01K6B3P2A9QRSTVWXYZ0123456","01K6B3P2A9QRSTVWXYZ0123457"],"note":"One refactoring covers both."}
```

### Q8 Unlink a commit

- **Request:** `DELETE /api/v1/requests/{requestId}/commits/{commitSha}`
- **Authentication:** B
- **Success:** 200 `RequestCommandResult`.
- **Errors:** 422 `invalid_input`: not linked; 409 `invalid_transition`, 409 `review_closed`.

The endpoint writes `commit.unlinked`. It deletes the pin ref `refs/holistic-review/commits/<sha>` when no other link in the repository uses the commit.

### Q10 Mark ready for review

- **Request:** `POST /api/v1/requests/{requestId}/ready`
- **Authentication:** B
- **Body:** [`RequiredNoteInput`](schemas/inputs.schema.json), `{note}`. Example: [fixtures/inputs.ready.json](fixtures/inputs.ready.json).
- **Success:** 200 `RequestCommandResult`. Example: [fixtures/request-command-result.ready.json](fixtures/request-command-result.ready.json).
- **Errors:**
  - 409 `commits_not_available`: at least one link is still pending;
  - 409 `repository_unavailable`: the fetch failed;
  - 409 `invalid_transition`, 409 `review_closed`.

Steps:

1. The server checks `review_closed` and `invalid_transition` first. If any link is `pending`, it then fetches the source synchronously, with a 60-second timeout.
2. The steps after the fetch, [webhooks-and-live-updates.md section 2.2](webhooks-and-live-updates.md#22-after-every-successful-fetch), write `commit.found` for each commit that is now present, and write its pin ref. They run in their own transaction, so the `events` of the result hold only `request.ready_for_review`.
3. Then it checks the links.

A request with zero links is allowed.

Example error, [fixtures/error.commits-not-available.json](fixtures/error.commits-not-available.json):

```json
{"error":{"code":"commits_not_available","message":"1 linked commit is not in the server clone after a fetch. Push it, then try again.","details":{"pendingCommits":["e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e"]}}}
```

### Q11 Mark won't do

- **Request:** `POST /api/v1/requests/{requestId}/wont-do`
- **Authentication:** S, B
- **Body:** [`WontDoInput`](schemas/inputs.schema.json), `{reason}`: 20 characters or more after trimming. Example: [fixtures/inputs.wont-do.json](fixtures/inputs.wont-do.json).
- **Success:** 200 `RequestCommandResult`.
- **Errors:** 422 `invalid_input`, 409 `invalid_transition`, 409 `review_closed`.

Example error, [fixtures/error.invalid-input.json](fixtures/error.invalid-input.json):

```json
{"error":{"code":"invalid_input","message":"The reason must have at least 20 characters.","details":{"violations":[{"field":"reason","message":"This value is too short. It should have 20 characters or more."}]}}}
```

### Q12 Verify a request

- **Request:** `POST /api/v1/requests/{requestId}/verify`
- **Authentication:** S
- **Body:** [`OptionalNoteInput`](schemas/inputs.schema.json).
- **Success:** 200 `RequestCommandResult`.
- **Errors:** 409 `invalid_transition`, 409 `review_closed`.

### Q13 Reopen a request

- **Request:** `POST /api/v1/requests/{requestId}/reopen`
- **Authentication:** S
- **Body:** [`RequiredNoteInput`](schemas/inputs.schema.json).
- **Success:** 200 `RequestCommandResult`.
- **Errors:** 409 `invalid_transition`, 409 `review_closed`.

## 4. Updates

- **Request:** `GET /api/v1/reviews/{reviewId}/updates`, endpoint [V10](rest-api.md#v10-get-updates)
- **Authentication:** S, B
- **Success:** 200 [`UpdatesPage`](schemas/updates-page.schema.json). Examples: [fixtures/updates-page.first-call.json](fixtures/updates-page.first-call.json) without a cursor, [fixtures/updates-page.cursor.json](fixtures/updates-page.cursor.json) with a cursor.
- **Errors:** 422 `invalid_input` when `cursor` is ahead of the server.

| Query | Type | Default | Notes |
|---|---|---|---|
| `cursor` | integer, 0 or more | none | The `cursor` of the previous response |
| `limit` | integer 1-500 | 100 | Events in cursor mode, requests in null mode |
| `includeOwn` | boolean | false | REST only. The UI sets it to true when it catches up: after a live-update reconnect, and when the tab becomes visible again. |

Algorithm. It runs in one read-only REPEATABLE READ transaction:

1. `S` = `tenant.event_seq` read in the snapshot. Every event with `seq <= S` is committed and visible, because numbers are assigned under the tenant row lock in commit order.
2. **Null mode**, without a cursor:
   - `requests` holds the requests of the review whose `awaiting` matches the caller: `agent` for tokens, `user` for sessions. For an agent, requests that another agent holds in `in_progress` are left out.
   - They are sorted by the oldest `lastEventSeq` first, with at most `limit` requests.
   - For each request, `newEvents` holds the events after the latest event of the caller on that request, or all events if the caller never acted. It holds at most the newest 10. `omittedEvents` counts the rest.
   - `reviewEvents` holds the `review.started`, `batch.submitted`, and `review.closed` events of the review, at most the newest 50.
   - `cursor = S` and `hasMore = false`.
3. **Cursor mode:**
   - If `cursor > S`, the call gets 422.
   - It selects the events of the review with `cursor < seq <= S`. It leaves out events whose actor is the caller, unless `includeOwn` is set. They are sorted by `seq`, with at most `limit` events.
   - Request events are grouped under each request in `requestIds`. The groups are sorted by their first `seq`, and each group carries the current `RequestSummary`. Review and batch events go to `reviewEvents`.
   - If more events remain, `hasMore = true` and `cursor` is the last included `seq`. Otherwise `cursor = S`. `omittedEvents` is 0.
4. `awaitingTotal` counts the requests whose `awaiting` matches the caller, with the same rule as null mode.

Example response for `cursor=1842`, [fixtures/updates-page.cursor.json](fixtures/updates-page.cursor.json):

```json
{
  "reviewId": "01K6B3A1B2C3D4E5F6G7H8J9KM",
  "reviewState": "open",
  "cursor": 1850,
  "hasMore": false,
  "awaitingTotal": 1,
  "requests": [
    {"request":{"id":"01K6B3P2A9QRSTVWXYZ0123456","reviewId":"01K6B3A1B2C3D4E5F6G7H8J9KM","repositoryId":"01K6B2Z0R3P0S1T2V3W4X5Y6Z7","status":"in_progress","awaiting":"agent","resolution":null,"closed":false,"anchor":{"kind":"symbol","path":"src/Billing/InvoiceTotals.php","range":{"startLine":38,"endLine":49},"symbol":{"language":"php","kind":"method","fqn":"App\\Billing\\InvoiceTotals::total()"}},"summary":"Use `Money` instead of `float` for totals.","author":{"kind":"user","userId":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","agentName":null,"name":"janklan","avatarUrl":"https://avatars.githubusercontent.com/u/1?v=4"},"claimedBy":{"kind":"agent","userId":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","agentName":"claude-code laptop","name":"claude-code laptop","avatarUrl":null},"claimedAt":"2026-09-29T07:40:00Z","commentCount":1,"commitCount":1,"pendingCommitCount":0,"outdated":false,"createdAt":"2026-09-29T07:20:00Z","updatedAt":"2026-09-29T07:50:00Z","lastEventSeq":1850,"carriedFrom":null},"newEvents":[{"seq":1850,"eventId":"01K6C2EVT00000000000001850","type":"request.commented","reviewId":"01K6B3A1B2C3D4E5F6G7H8J9KM","requestId":"01K6B3P2A9QRSTVWXYZ0123456","requestIds":["01K6B3P2A9QRSTVWXYZ0123456"],"actor":{"kind":"user","userId":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","agentName":null,"name":"janklan","avatarUrl":"https://avatars.githubusercontent.com/u/1?v=4"},"occurredAt":"2026-09-29T07:50:00Z","body":"Keep the old method as deprecated for one release.","commentId":"01K6C3C0MMENT0000000000002","question":false,"batchId":null,"commitSha":null,"statusAfter":"in_progress"}],"omittedEvents":0}
  ],
  "reviewEvents": []
}
```

## 5. Drafts

Drafts are the pending batch. They are private to their user and are not events. A user has at most 500 drafts per review. More get 422.

### D1 List drafts

- **Request:** `GET /api/v1/reviews/{reviewId}/drafts`
- **Authentication:** S
- **Success:** 200 [`DraftList`](schemas/lists.schema.json), sorted by `createdAt`. Example: [fixtures/lists.draft-list.json](fixtures/lists.draft-list.json).
- **Errors:** common errors only.

It also works on a closed review, so the user can delete drafts that are left.

### D2 Create a draft

- **Request:** `POST /api/v1/reviews/{reviewId}/drafts`
- **Authentication:** S
- **Body:** [`CreateDraftInput`](schemas/inputs.schema.json), one of `{"kind":"new_request","anchor":AnchorInput,"body":"..."}`, the general form `{"kind":"new_request","anchor":null,"body":"..."}`, or `{"kind":"reply","requestId":"...","body":"..."}`. Examples: [fixtures/inputs.create-draft-new-request.json](fixtures/inputs.create-draft-new-request.json), [fixtures/inputs.create-draft-general-request.json](fixtures/inputs.create-draft-general-request.json), [fixtures/inputs.create-draft-reply.json](fixtures/inputs.create-draft-reply.json).
- **Success:** 201 [`Draft`](schemas/draft.schema.json) with `Location: /api/v1/drafts/{id}`. Examples: [fixtures/draft.new-request.json](fixtures/draft.new-request.json), [fixtures/draft.new-general-request.json](fixtures/draft.new-general-request.json), [fixtures/draft.reply.json](fixtures/draft.reply.json).
- **Errors:**
  - 404 `not_found`: the request is unknown;
  - 422 `invalid_input`: anchor errors, or the request belongs to another review;
  - 409 `review_closed`;
  - 409 `repository_unavailable`: the clone is gone, for a `new_request` draft with an anchor.

The server captures and validates the anchor at creation, as in [Q2](#q2-open-a-request). The review commit never changes, so the anchor stays valid until the submission. A `new_request` draft with an absent or null `anchor` is general and has no capture.

### D3 Update a draft

- **Request:** `PATCH /api/v1/drafts/{draftId}`
- **Authentication:** S
- **Body:** [`UpdateDraftInput`](schemas/inputs.schema.json), `{body}`. Example: [fixtures/inputs.update-draft.json](fixtures/inputs.update-draft.json).
- **Success:** 200 [`Draft`](schemas/draft.schema.json)
- **Errors:** 409 `review_closed`.

The anchor cannot change. To move a draft, delete it and create a new one.

### D4 Delete a draft

- **Request:** `DELETE /api/v1/drafts/{draftId}`
- **Authentication:** S
- **Success:** 204, no body. It also works on closed reviews.
- **Errors:** common errors only.

### D5 Submit drafts

- **Request:** `POST /api/v1/reviews/{reviewId}/drafts/submit`
- **Authentication:** S
- **Body:** [`SubmitDraftsInput`](schemas/inputs.schema.json), `{summary?}`. Example: [fixtures/inputs.submit-drafts.json](fixtures/inputs.submit-drafts.json).
- **Success:** 201 [`BatchSubmission`](schemas/batch-submission.schema.json)
- **Errors:** 422 `invalid_input` when there are no drafts; 409 `review_closed`.

One transaction:

1. takes all drafts of the caller in the review, sorted by `createdAt`, and creates a `batchId`;
2. for each draft, writes `request.opened` or `request.commented` with that `batchId`. A general draft opens a general request;
3. writes `batch.submitted` with `summary`, `requestIds`, and `commentIds`;
4. deletes the drafts.

Example response, [fixtures/batch-submission.submitted.json](fixtures/batch-submission.submitted.json):

```json
{
  "batchId": "01K6B3P1Z8Y7X6W5V4T3S2R1Q0",
  "reviewId": "01K6B3A1B2C3D4E5F6G7H8J9KM",
  "summary": "First pass over billing.",
  "submittedAt": "2026-09-29T07:20:00Z",
  "seq": 1832,
  "openedRequests": [
    {"id":"01K6B3P2A9QRSTVWXYZ0123456","reviewId":"01K6B3A1B2C3D4E5F6G7H8J9KM","repositoryId":"01K6B2Z0R3P0S1T2V3W4X5Y6Z7","status":"open","awaiting":"agent","resolution":null,"closed":false,"anchor":{"kind":"symbol","path":"src/Billing/InvoiceTotals.php","range":{"startLine":38,"endLine":49},"symbol":{"language":"php","kind":"method","fqn":"App\\Billing\\InvoiceTotals::total()"}},"summary":"Use `Money` instead of `float` for totals.","author":{"kind":"user","userId":"01K6AZZ9Y8X7W6V5T4S3R2Q1P0","agentName":null,"name":"janklan","avatarUrl":"https://avatars.githubusercontent.com/u/1?v=4"},"claimedBy":null,"claimedAt":null,"commentCount":0,"commitCount":0,"pendingCommitCount":0,"outdated":null,"createdAt":"2026-09-29T07:20:00Z","updatedAt":"2026-09-29T07:20:00Z","lastEventSeq":1830,"carriedFrom":null}
  ],
  "replies": [
    {"requestId":"01K6B3P2A9QRSTVWXYZ0123457","commentId":"01K6C3C0MMENT0000000000001"}
  ]
}
```

## 6. API tokens

### T1 List API tokens

- **Request:** `GET /api/v1/tokens`
- **Authentication:** S
- **Success:** 200 [`TokenList`](schemas/lists.schema.json) with every token of the user: active, expired, and revoked. It is sorted by `createdAt` descending. Example: [fixtures/lists.token-list.json](fixtures/lists.token-list.json).
- **Errors:** common errors only.

### T2 Create an API token

- **Request:** `POST /api/v1/tokens`
- **Authentication:** S
- **Body:** [`CreateTokenInput`](schemas/inputs.schema.json), `{name, repositoryId?, expiresAt?}`. Example: [fixtures/inputs.create-token.json](fixtures/inputs.create-token.json).
  - `name` becomes the agent name on every event that the token makes.
  - `repositoryId: null` gives the scope `all_repositories`. A repository ID gives the scope `repository`.
  - `expiresAt` is null, which means no expiry, or a time from now plus 1 minute to now plus 366 days.
  - A user has at most 50 active tokens.
- **Success:** 201 [`ApiTokenWithSecret`](schemas/api-token.schema.json)
- **Errors:** 404 `not_found` for `repositoryId`; 422 `invalid_input`.

Secret: `"hr_" + base64url_no_padding(random_bytes(32))`, 46 characters in total. The server stores `hash('sha256', secret)` as lower-case hex, and a `prefix` of the first 9 characters. Only this response contains the secret. The UI shows it once.

Example response, [fixtures/api-token.with-secret.json](fixtures/api-token.with-secret.json):

```json
{
  "id": "01K6C0T0KEN0000000000000AC",
  "name": "codex desktop",
  "scope": "all_repositories",
  "repositoryId": null,
  "repositoryName": null,
  "prefix": "hr_Zm9vYm",
  "state": "active",
  "createdAt": "2026-09-29T08:00:00Z",
  "expiresAt": null,
  "lastUsedAt": null,
  "revokedAt": null,
  "secret": "hr_Zm9vYmFyYmF6cXV4cXV1eGNvcmdlZ3JhdWx0Z2Fyc3A"
}
```

### T3 Revoke an API token

- **Request:** `DELETE /api/v1/tokens/{tokenId}`
- **Authentication:** S
- **Success:** 204, no body. A second revoke also returns 204.
- **Errors:** common errors only.

The endpoint sets `revokedAt`. The row stays, so T1 lists the revoked token.

## 7. Routes outside /api/v1

| Method | Path | Authentication | Purpose |
|---|---|---|---|
| GET | `/`, `/repositories`, `/repositories/{id}`, `/reviews/{id}`, `/reviews/{id}/files/{path}`, `/reviews/{id}/requests`, `/requests/{id}`, `/settings` | Session, else a redirect to `/login` | Twig shell page for the React single-page application, with the Reprise entry tags. React Router 8.4.0 in Data Mode renders the screens. |
| GET | `/login` | public | "Sign in with GitHub" when `GITHUB_APP_CLIENT_ID` is not empty. The local sign-in button when the local sign-in is on. With neither: "No sign-in method is configured." |
| GET | `/connect/github` | public. It exists only when `GITHUB_APP_CLIENT_ID` is not empty. | HWIOAuthBundle redirect to GitHub, route `hwi_oauth_service_redirect` |
| GET | `/login/check-github` | public. It exists only when `GITHUB_APP_CLIENT_ID` is not empty. | OAuth callback, which is also the callback URL of the GitHub App. Lists installations and repositories. Creates the tenant and the user at the first sign-in. |
| POST | `/login/local` | public. It exists only when `APP_ENV=dev` and `APP_LOCAL_SIGN_IN` is not empty. | Local sign-in as the user named by `APP_LOCAL_SIGN_IN=<login>`. Creates its tenant and user at first use. A local run needs no GitHub App. |
| GET | `/_e2e/sign-in/{login}` | public. It exists only in the `e2e` environment. | Signs the browser of a full-stack E2E test in as `<login>` |
| POST | `/logout` | session, CSRF | Sign out |
| GET | `/github/installed` | session | Setup URL of the GitHub App. Redirects through `/connect/github?_target_path=/repositories` to list the installations again. |
| GET | `/docs` | public | Documentation index, rendered from `docs/user/index.md` |
| GET | `/docs/{page}` | public | One page of `docs/user/*.md`, rendered as HTML with `twig/markdown-extra` 3.29.0 and `league/commonmark` 2.10.3. Raw HTML is escaped and unsafe links are dropped. `page` must be in a whitelist of slugs built from the directory listing, for example `getting-started`, `local-run`, `connect-your-agent`, `reviewing`. Other values get 404. |
| GET | `/docs/skill/SKILL.md` | public | Raw skill file from `docs/user/skill/SKILL.md`: `text/markdown; charset=utf-8`, `Content-Disposition: inline; filename="SKILL.md"`. With `?download=1`, `attachment`. ETag is the SHA-256 of the content; `Cache-Control: public, max-age=300`. Install: `curl -fsSL https://<host>/docs/skill/SKILL.md --create-dirs -o .claude/skills/holistic-review/SKILL.md` |
| GET | `/docs/schemas/v1/{file}.schema.json` | public | The files of `docs/specs/schemas/`, `application/schema+json` |
| GET | `/healthz` | public | Health check of the container: 200 with a short HTML body |
| POST, GET, DELETE | `/_mcp` | bearer | MCP Streamable HTTP. See [mcp.md](mcp.md). |
| POST | `/webhook/github` | HMAC signature | GitHub App webhooks. See [webhooks-and-live-updates.md](webhooks-and-live-updates.md). |
| GET | `/.well-known/mercure` | Mercure cookie | Mercure hub built into FrankenPHP |
