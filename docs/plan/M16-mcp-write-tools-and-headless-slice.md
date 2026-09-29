# M16 MCP write tools and headless slice

Workflow, conventions, and status: [README](README.md).

## Goal

Agents claim, discuss, link commits, and finish requests over MCP and REST. One functional test runs the whole slice without a browser. This completes the headless slice.

## Depends on

M15, M09.

## In scope

- Write tools. Signatures, constraints, annotations, and output schemas equal docs/specs/mcp.md section 4:
  - `claim_request(id)`;
  - `release_request(id, note)`;
  - `reply_to_request(id, body, question)`;
  - `link_commits(commitSha, requestIds, note)`. `requestIds` needs `#[Schema(items: ...)]`;
  - `unlink_commit(commitSha, requestId)`;
  - `mark_ready_for_review(id, note)`;
  - `mark_wont_do(id, reason)`.
- REST endpoints for agents, bearer only:
  - Q5 `POST /api/v1/requests/{requestId}/claim`;
  - Q7 `POST /api/v1/commit-links`;
  - Q8 `DELETE /api/v1/requests/{requestId}/commits/{commitSha}`;
  - Q10 `POST /api/v1/requests/{requestId}/ready`.
  Q4 comments, Q6 release, and Q11 wont-do from M13 also accept bearer callers.
- `LinkCommits`, Q7:
  - `commitSha` is a full SHA. `requestIds` holds 1 to 50 unique IDs of one review. Requests of several reviews give 422 `invalid_input`. An unknown request ID gives 404;
  - every request must be `in_progress` and claimed by the caller. The first one that fails gives 409 `invalid_transition`, and nothing is written;
  - the availability check runs `git cat-file -e` in the clone, without a fetch. A present commit gets the link state `available`, `foundAt` equal to the link time, and the pin `refs/holistic-review/commits/<sha>`, decision D26. As in V1, the pin is written before the transaction and deleted after a rollback, unless another link in the repository uses the commit. `commit.linked` then carries `available: true`, `subject`, and `committedAt` from git, read by a new `GitReader::commitInfo()`;
  - a missing commit gets the link state `pending`. After `CommandExecutor::execute()` returns, `LinkCommits` requests an asynchronous fetch through the `fetchRequested` flag of M09, with a `DelayStamp` of 5 seconds. This is the debounce per repository;
  - one `commit.linked` event names the newly linked requests. Pairs that are already linked are skipped. The response is `LinkCommitsResult`.
- `UnlinkCommit`, Q8: writes `commit.unlinked` and removes the commit pin when no other link in the repository uses the commit. A commit that is not linked gives 422.
- `MarkReadyForReview`, Q10:
  1. Decide `review_closed` and `invalid_transition` first, so that a refused command does not wait for a fetch and gets its error in the check order of docs/specs. Then, if any link is `pending`, fetch synchronously with the 60-second deadline of M09.
  2. The fetch dispatches `RepositoryFetched`. `ResolvePendingCommitLinks` writes `commit.found` and the pins in its own transaction. `MarkReadyForReview` does not write `commit.found`. It reads the links again, and its `events` hold only `request.ready_for_review`.
  3. Then check the links. A link that is still `pending` gives 409 `commits_not_available` with `pendingCommits`. A failed fetch gives 409 `repository_unavailable` with reason `fetch_failed`. Zero links are allowed.
- The fetch gets the SHAs of pending links in its second step, as M08 says, into `refs/holistic-review/commits/<sha>`.
- `ResolvePendingCommitLinks`, a listener on `RepositoryFetched`: for each pending link of the repository whose commit is now present, set it `available`, pin it, and write one `commit.found` per review, with `subject` and `committedAt` from git. It is the only writer of `commit.found`.
- Every agent write on a closed review gives `review_closed`.
- A cross-tenant case for every new route and tool.

## Out of scope

- UI.
- Live updates: M24.

## Files (representative)

- `src/Mcp/Tool/*.php`.
- `src/Controller/Api/AgentRequestController.php`, `src/Controller/Api/CommitLinkController.php`.
- `src/Application/LinkCommits.php`, `UnlinkCommit.php`, `MarkReadyForReview.php`, `ResolvePendingCommitLinks.php`.
- `tests/Functional/Mcp/Write*Test.php`, `tests/Functional/Api/Agent*Test.php`, `tests/Functional/Slice/HeadlessSliceTest.php`.

## Tasks (ordered)

1. Implement the use cases.
2. Write the tools and the REST endpoints.
3. Add the cross-tenant cases.
4. Write the slice test.
5. Extend `McpRestParityTest` to the write results.
6. Extend `ToolsListContractTest` and `tests/Fixtures/mcp/tools-list.json` to all 12 tools.

## Acceptance tests

All are functional tests. Every response and every `structuredContent` validates against its schema.

- **F `ClaimReleaseToolTest`** proves claim and release over MCP.
  - "claim_request sets in_progress"
  - "A repeated claim returns an empty events list"
  - "A claim of another agent's request gives isError invalid_transition with claimedBy"
  - "release_request restores the status before the claim"
- **F `ReplyToolTest`** proves the conversation.
  - "question true hands the turn to the user"
  - "The user's reply hands the turn back to the agent"
  - "A whitespace-only body gives isError invalid_input"
- **F `LinkCommitsTest`** proves Q7 and `link_commits`.
  - "An available commit gets the state available and a pin"
  - "A missing commit gets the state pending and queues a fetch"
  - "A second pending link while the fetch is queued dispatches no second fetch"
  - "A short SHA gives -32602 over MCP and 422 over REST"
  - "One commit covers two requests"
  - "Requests of two reviews give invalid_input"
  - "A request that the caller does not hold gives invalid_transition and writes nothing"
  - "Linking the same pair again writes no event"
- **F `UnlinkCommitTest`** proves Q8.
  - "Unlinking removes the link and the unused pin"
  - "Unlinking a commit that is not linked gives invalid_input"
- **F `MarkReadyTest`** proves Q10.
  - "Runs one fetch, the listener writes commit.found once, then the request is ready"
  - "The result events hold only request.ready_for_review"
  - "A commit that is still missing gives commits_not_available with its SHA"
  - "Zero links are allowed"
  - "A request that is not in progress gets invalid_transition without a fetch"
- **F `PendingLinkResolutionTest`** proves resolution after a fetch.
  - "A later fetch resolves the pending link with commit.found by the system and a pin"
- **F `AgentWritesOnClosedReviewTest`** proves the closed-review rule for agents.
  - "Every write tool and agent endpoint gives review_closed on a closed review"
- **F `WontDoToolTest`** proves the reason rules.
  - "A reason shorter than 20 characters gives -32602"
  - "A reason with fewer than 20 characters after trimming gives isError invalid_input"
  - "A valid reason sets wont_do and waits for the user"
- **F `AgentActorKindTest`** proves the actor-kind rule for agent endpoints.
  - "Claim with a session gives 403 forbidden"
  - "Comments, release, and wont-do accept a bearer token"
- **F `HeadlessSliceTest`** proves the whole flow through the public interfaces, in this order:
  1. The user connects a local source and starts a review over session REST.
  2. The user opens a range request.
  3. With a token, the agent calls `list_reviews`, `list_requests`, `get_request`, and `claim_request`.
  4. The test commits a fix on a branch of the source.
  5. The agent calls `link_commits` and `mark_ready_for_review`.
  6. The user verifies. The request is `verified` and awaits nobody.
  7. `GET /api/v1/reviews/{reviewId}/updates?cursor=0&includeOwn=true` for the user: the union of `reviewEvents` and all `newEvents`, de-duplicated by `seq`, equals the event log of the review. The same union from the agent's `get_updates` with cursor 0 equals that log without the agent's own events.
  - "The user and the agent complete a request through REST and MCP"
- **F `McpRestParityTest`**, extended, proves equal write results.
  - "Each write tool returns the same body as its REST endpoint"

## Verification commands

```sh
make test
make check
```

By hand: connect Claude Code to a real local repository under `LOCAL_REPOSITORIES_HOST_DIR`. Open a request with a `fetch()` call in the browser console. Ask the agent to work the review, and watch the status change.

## Done

The slice works through the public interfaces only.
