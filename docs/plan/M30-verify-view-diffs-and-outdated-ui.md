# M30 Verify view diffs and outdated UI

Workflow, conventions, and status: [README](README.md).

## Goal

Show the changes of each linked commit with `FileDiff`, and mark outdated threads.

## Depends on

M29, M22.

## In scope

- Q9 `GET /api/v1/requests/{requestId}/commits/{commitSha}`, as docs/specs/rest-api-requests.md section 2 says:
  - `CommitDiffQuery` compares the commit with its first parent through `git diff-tree -r -M --numstat` plus the raw output;
  - at most 300 files, with `truncated` beyond that;
  - per file: `status`, paths, blob SHAs, modes, `additions`, `deletions`, `binary`, `tooLarge` when a side is above 1 MiB, and `touchesAnchor`;
  - `location` is the request located at this commit, with target `linked_commit`;
  - errors: 404 when the commit is not linked to the request, 409 `commits_not_available` when the link is pending, 409 `repository_unavailable`.
- `assets/code/CommitDiff.tsx`: the only wrapper around `FileDiff`. It loads the old and the new side through R7 with `oldBlobSha` and `newBlobSha`.
- A "Changes" tab in the request detail: one section per linked commit. Files with `touchesAnchor` come first.
- An "Outdated" `Label` in the request list and on threads, and the `outdated` filter in the list URL.
- The location status and confidence in the request detail.

## Out of scope

- Suggested changes: [docs/backlog/suggested-changes.md](../backlog/suggested-changes.md).

## Files (representative)

- `src/Application/Query/CommitDiffQuery.php`, `src/Controller/Api/CommitDiffController.php`, `src/Serializer/Normalizer/CommitDiffNormalizer.php`.
- `assets/code/CommitDiff.tsx`, `assets/routes/requests/RequestDetail.tsx`, `assets/requests/OutdatedLabel.tsx`, `assets/requests/LocationInfo.tsx`.
- `tests/Functional/Api/CommitDiffApiTest.php`, `tests/E2E/ChangesTabTest.php`, `tests/E2E/OutdatedLabelTest.php`.

## Tasks (ordered)

1. Write the query, the endpoint, and the cross-tenant case.
2. Build `CommitDiff.tsx` and the "Changes" tab.
3. Add the Outdated label, the filter, and the location display.

## Acceptance tests

- **F `CommitDiffApiTest`** proves Q9, with a cross-tenant case.
  - "Returns the files of the commit with blob SHAs, counts, and touchesAnchor"
  - "The location has the target linked_commit"
  - "A commit that is not linked gives 404"
  - "A pending link gives 409 commits_not_available"
- **E `ChangesTabTest`** proves the diff view.
  - "The Changes tab shows one FileDiff per linked commit with the anchored file first"
- **E `OutdatedLabelTest`** proves the outdated display.
  - "After a default-branch commit edits the region and the user clicks Refresh, the request shows Outdated"
  - "The outdated filter lists the request"

## Verification commands

```sh
make check
```

## Done

The user verifies a request with the commit diff in view, and sees which threads are outdated.
