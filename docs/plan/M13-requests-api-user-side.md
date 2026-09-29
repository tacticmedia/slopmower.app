# M13 Requests API, user side

Workflow, conventions, and status: [README](README.md).

## Goal

The user opens requests on lines, ranges, and files with server-side anchor capture, converses, and changes the status over REST. The contract is docs/specs/rest-api-requests.md sections 1 to 4.

## Depends on

M12.

## In scope

- `AnchorFactory`, as Q2 says:
  - reads the blob at the review commit and builds the snapshot, architecture section 5.1;
  - `line` needs `startLine == endLine`; `range` needs `endLine > startLine`; both need a range inside `lineCount` and a renderable file;
  - `file` works for any file, also binary and too-large files;
  - the stored snapshot always comes from git. Unknown body properties are ignored, so a snapshot sent by a client has no effect.
- `RequestQuery`:
  - Q1 filters `status`, `awaiting`, `path` as a prefix, `outdated`; `sort` with `created`, `updated`, or `path`; `limit`; `cursor`. `total` counts all matches. Closed reviews can be listed. `outdated` is null until M29, and null never matches the filter;
  - Q3 returns the full `Request`: the anchor, the body, the `thread`, the `commits` sorted by `linkedAt`, `allowedActions` for the caller, `reviewState`, `reviewCommitSha`, `location: null` and `excerpt: null` until M29.
- The thread holds, in `seq` order: the request's own events, the `commit.linked` events whose `requestIds` contain it, and the `commit.found` events of its commits.
- Normalizers for `RequestSummary`, `Request`, `ThreadEntry`, `Event`, `CommitLink`, and `RequestCommandResult`. `summary` is the first non-blank line of the body, trimmed and cut as `request-summary.schema.json` says.
- `UpdatesQuery` for V10, as docs/specs/rest-api-requests.md section 4 says: one read-only REPEATABLE READ transaction, the null mode and the cursor mode, `includeOwn`, `hasMore`, and `awaitingTotal`. A session caller waits for `awaiting = user`.
- Endpoints:
  - Q2 `POST /api/v1/reviews/{reviewId}/requests`, session only: 201 with the full `Request` and `Location`;
  - Q1 `GET /api/v1/reviews/{reviewId}/requests`;
  - Q3 `GET /api/v1/requests/{requestId}`;
  - Q4 `POST /api/v1/requests/{requestId}/comments`: 201 with `RequestCommandResult`;
  - Q6 `POST /api/v1/requests/{requestId}/release`: "Unassign" for the user;
  - Q11 `POST /api/v1/requests/{requestId}/wont-do`;
  - Q12 `POST /api/v1/requests/{requestId}/verify`, session only;
  - Q13 `POST /api/v1/requests/{requestId}/reopen`, session only;
  - V10 `GET /api/v1/reviews/{reviewId}/updates?cursor=`.
- Every write on a closed review gives 409 `review_closed` with `{reviewId, closedAt}`.
- A cross-tenant case for every new route.

## Out of scope

- Symbol anchors: M27.
- Drafts: M25.
- Agent commands: M16. Q4, Q6, and Q11 with a bearer token are tested in M16.
- `at` and `context` on Q3: M29.

## Files (representative)

- `src/Application/AnchorFactory.php`, `src/Application/Query/RequestQuery.php`, `src/Application/Query/UpdatesQuery.php`.
- `src/Controller/Api/RequestController.php`, `src/Controller/Api/RequestCommandController.php`, `src/Controller/Api/UpdatesController.php`, `src/Controller/Api/Input/*.php`.
- `src/Serializer/Normalizer/Request*.php`, `ThreadEntryNormalizer.php`, `EventNormalizer.php`, `CommitLinkNormalizer.php`.
- `tests/Functional/Api/Request*Test.php`, `tests/Functional/Api/Updates*Test.php`.

## Tasks (ordered)

1. Implement `AnchorFactory` for `line`, `range`, and `file`.
2. Implement the queries and the normalizers.
3. Write the controllers and their input DTOs.
4. Add the cross-tenant cases.

## Acceptance tests

All are functional tests. Every JSON response validates against its schema.

- **F `OpenRequestTest`** proves Q2.
  - "Opens line, range, and file requests with 201 and a Location header"
  - "The snapshot and the blob SHA match git"
  - "A new request is open and awaits the agent"
- **F `AnchorValidationTest`** proves the anchor rules.
  - "A line outside the file gives 422 invalid_input"
  - "A start after the end, a line anchor with two lines, and a range anchor with one line give 422"
  - "A missing path gives 422"
  - "A line anchor on a binary file gives 422, and a file anchor on it works"
- **F `ClientSnapshotIgnoredTest`** proves that the client cannot set a snapshot.
  - "A snapshot in the request body is ignored"
- **F `ThreadTest`** proves the thread.
  - "Comments appear in order with actor user"
  - "A user comment with question true gives 422"
- **F `UserCommandsTest`** proves the user commands of architecture section 6.
  - "Verify on an open request gives 409 invalid_transition with details"
  - "Reopen needs a note"
  - "A user wont_do closes the request"
  - "Unassign restores the status before the claim"
- **F `ClosedReviewWritesTest`** proves the closed-review rule, decision D27.
  - "Every write on a closed review gives 409 review_closed"
  - "Reads still work and allowedActions is empty"
- **F `ReviewSeparationTest`** proves review separation, decision D8.
  - "Review A's requests and updates never appear in review B"
- **F `RequestListFilterTest`** proves Q1.
  - "Filters by status, awaiting, and path prefix"
  - "Sorts by created, updated, and path"
  - "total counts all matches regardless of the cursor"
- **F `UpdatesCursorTest`** proves V10.
  - "Without a cursor, returns the requests that wait for the user with their new events"
  - "With a cursor, groups events by request and leaves out own events unless includeOwn is set"
  - "hasMore and cursor page through the events"
  - "A cursor ahead of the server gives 422"

## Verification commands

```sh
make test
make check
```

## Done

Every user command of architecture section 6 works over REST.
