# Carry over open requests to a new review

Status: backlog. In the proof of concept each review is pinned to one commit and keeps its own requests.

## Why

- A user often starts a new review at a later commit while requests of the older review are still open. The user must then type those requests again.
- The re-anchoring algorithm already finds an anchor at another commit, so the server can move the requests to the new review commit.

## What

- When the user starts a review, the form offers **Carry over open requests from** with a list of the other open reviews of the repository.
- The server takes each request of the source review with status `open` or `reopened`. A request that is `in_progress` must be released first. Requests that wait for the user stay in the source review.
- For each request, the server runs `locate(anchor, newReviewCommit)` from architecture section 5.4:

  | Location | New anchor at the new review commit |
  |---|---|
  | `exact`, `shifted` | Same kind, the located path and range, a new snapshot |
  | `changed` | Same kind at the located range, a new snapshot, and a first comment that says that the code changed |
  | `orphaned` with an existing path | A `file` anchor on that path, and a first comment with the old snapshot |
  | `orphaned` without a path | Not carried. The result lists it with the reason. |

- The new request gets the body of the source request and a link to the source thread. `request.opened` gets an additive payload field `carriedFrom: {reviewId, requestId}`.
- The source request stays unchanged. The user closes the source review when the carry-over is complete.
- A request is never dropped silently. The result of the start lists every source request with its outcome.

## Acceptance criteria

1. The failure cases F1 to F14 of architecture section 5.5 each give the outcome of the table above in a functional test with real git repositories.
2. A carried request has a valid anchor at the new review commit, and its `carriedFrom` points to the source request.
3. Requests that are not carried appear in the result with a reason.
4. The source review and its requests do not change.
5. A Panther test starts a review with carry-over and sees the carried threads at their new lines.

## Dependencies

- M28 and M29 re-anchoring: `locate()`.
- M12 Reviews API: the start command.
- M27 symbol anchors: symbol anchors keep their kind when the FQN is found.
