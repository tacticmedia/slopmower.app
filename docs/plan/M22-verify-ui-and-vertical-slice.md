# M22 Verify UI and vertical slice

Workflow, conventions, and status: [README](README.md).

## Goal

The user lists requests, follows the agent's work, and verifies or reopens it. One E2E test proves the whole slice in the browser. This completes the vertical slice.

## Depends on

M16, M19, M21.

## In scope

- `/reviews/:id/requests`, from Q1:
  - columns: a status `Label`, "Waiting for you", `path:Lx-Ly`, "claimed by" with the claim age of decision D6, and the update time;
  - filters in the URL: status through a `SegmentedControl`, `awaiting=user` for "Waiting for you", and a path prefix.
- `/requests/:id`, from Q3:
  - the snapshot lines of the anchor;
  - the thread, with agent names;
  - the linked commits with the state pending or available;
  - buttons from `allowedActions`: "Resolve conversation" for verify, Q12; "Reopen", Q13, with a required note; "Close as won't do", Q11, with 20 or more characters; "Unassign" for release, Q6;
  - a reply box, Q4;
  - read-only when the review is closed: no action buttons, no reply box.

## Out of scope

- Live updates: M24. This milestone reloads data through the route loaders.
- Commit diffs: M30.

## Files (representative)

- `assets/routes/requests/RequestList.tsx`, `assets/routes/requests/RequestDetail.tsx`.
- `assets/requests/StatusLabel.tsx`, `RequestActions.tsx`, `CommitList.tsx`, `ClaimAge.tsx`.
- `assets/requests/allowedActions.ts`, `assets/requests/claimAge.ts`, and their `*.test.ts` files.
- `tests/E2E/VerticalSliceTest.php`, `ReopenTest.php`, `UnassignTest.php`, `ClosedRequestReadOnlyTest.php`. They use `McpHttpClient` from M19.

## Tasks (ordered)

1. Build the request list with its URL filters.
2. Build the request detail with its actions and the reply box.
3. Write the slice test, then the other tests.
4. Set the Notes column of M22 in the README status table to "Vertical slice complete".

## Acceptance tests

- **V `AllowedActionsMappingTest`** proves that buttons follow the server.
  - "Maps each allowed action to its button and ignores unknown actions"
- **V `ClaimAgeTest`** proves the claim age text.
  - "Formats the claim age in minutes, hours, and days"
- **E `VerticalSliceTest`** proves the whole flow in the browser, in this order:
  1. Sign in through the `e2e` route, connect the test's local repository, and start a review.
  2. Comment on a range in the code view.
  3. Issue a token in Settings.
  4. Over MCP on HTTP, the agent calls `list_reviews`, `list_requests`, and `claim_request`.
  5. The test commits a fix in the source.
  6. The agent calls `link_commits` and `mark_ready_for_review`.
  7. After a reload, the user sees "Ready for review" with an available commit.
  8. "Resolve conversation" makes the request Verified.
  - "The user and the agent complete a request through the browser and MCP"
- **E `ReopenTest`** proves reopen and questions.
  - "Reopen with a note makes the request wait for the agent"
  - "An agent question shows under Waiting for you"
- **E `UnassignTest`** proves Unassign.
  - "Unassign restores the status before the claim and clears claimed by"
- **E `ClosedRequestReadOnlyTest`** proves the read-only detail.
  - "A request of a closed review shows no action buttons and no reply box"

## Verification commands

```sh
make check
```

## Done

The slice runs in the browser, and the README status table marks it complete.
