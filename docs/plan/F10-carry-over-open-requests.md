# F10 Carry over open requests

Workflow, conventions, and status: [feature track](features.md). The design comes from the former backlog item "Carry over open requests" (removed by F10, see git history).

## Goal

When a user starts a review at a later commit, the open requests of an older review of the same repository move to the new review, re-anchored at its commit. Nothing is typed again, and no request is dropped silently.

## Decisions

- **Endpoint.** A new additive endpoint, `POST /api/v1/reviews/{reviewId}/carry-over`, with body `{fromReviewId}`. V1 (start review) does not change.
  - The start form calls V1, then this endpoint. It can also be called later on an open review.
  - It returns a result with one entry per source request: carried with the new request ID, or not carried with a reason.
  - It is idempotent: a source request that already has a carried copy in the target review is reported as already carried, not copied again.
- **Payload.** `request.opened` gets an additive payload field `carriedFrom: {reviewId, requestId}`. It is null in every older event, and the codec reads it as optional (D37).
  - Q3 and Q1 show `carriedFrom` as an additive response field.
  - The thread of a carried request links to its source.
- **What carries.** Source requests with status `open` or `reopened` carry. All others stay and are listed with their status as the reason:
  - `in_progress` must be released first;
  - requests that wait for the user, and closed ones, stay.
  - A general request (anchor null, F01) carries as a general request.
- **Anchors.** Each anchored request is located at the new review commit with `Locator` (architecture section 5.4):

  | Location | New anchor at the new review commit |
  |---|---|
  | `exact`, `shifted` | Same kind, the located path and range, a new snapshot |
  | `changed` | Same kind at the located range, a new snapshot, and a first comment that says the code changed |
  | `orphaned` with an existing path | A `file` anchor on that path, and a first comment with the old snapshot |
  | `orphaned` without a path | Not carried; the result gives the orphan reason |

- **Events.** The new requests are written under the tenant lock through the normal event path: `request.opened` per request, plus the first comments, in one batch. The source review and its requests do not change. The user closes the source review when ready.
- **MCP.** No MCP tool: agents never open requests (architecture section 7.1).

## Depends on

M28, M29, M12, M27, F01. An additive contract change: a new endpoint, a new schema and fixtures, an additive event field, and an additive response field.

## In scope

- Specs first: the endpoint, its request and result schemas and fixtures, `carriedFrom` in the request and request-summary schemas, the `request.opened` payload, and the error cases. A source review of another repository, a closed target review, or a source equal to the target is 422 `invalid_input`; a review that is not visible is 404.
- The endpoint, the re-anchoring per request, and the batch write.
- The UI:
  - The start form offers **Carry over open requests from**, a list of the other open reviews of the repository.
  - After the start, a result panel lists every source request with its outcome.
  - A carried thread shows "Carried from" with a link to its source request.
- Docs: `docs/user/reviewing.md`, architecture sections 3 (event payload), 7 (endpoint list), and 11 (a decision row), and the developer guide. Delete the backlog item, and remove it from the backlog index.

## Out of scope

- Moving drafts.
- Closing the source review automatically.
- Carrying requests between repositories.

## Acceptance tests

- **F**, one case per outcome row:
  - `exact` and `shifted` keep the kind and get a new range and snapshot;
  - `changed` adds the code-changed comment;
  - `orphaned` with a path becomes a file anchor with the old snapshot comment;
  - `orphaned` without a path is not carried.
- **F:**
  - A general request carries as general.
  - `in_progress` and `ready_for_review` sources are listed as not carried.
  - A second call reports "already carried" and creates nothing.
  - The source review is unchanged.
  - A cross-tenant case for the new route, and inventory entries.
- **U:** the `request.opened` codec with and without `carriedFrom`. An old event still decodes.
- **UI:** the start form with carry-over shows the result panel, and a carried thread shows its source link.
- No new full-stack E2E test. The functional tests use real git repositories, and the UI test covers the form.

## Verification

`make check`. Then a manual check on the dev app: start a second review of this repository at a newer commit, carry over from the first, and read the result panel.
