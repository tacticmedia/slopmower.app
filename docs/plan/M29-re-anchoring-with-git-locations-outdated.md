# M29 Re-anchoring with git, locations, outdated

Workflow, conventions, and status: [README](README.md).

## Goal

Locate anchors at a target commit for the cases F1 to F14 of architecture section 5.5, cache the results, flag outdated requests, and support `at` on `get_request` and Q3.

## Depends on

M28, M26, M16. M24 for the `outdated` live update.

## In scope

- `Locator`, as architecture section 5.4 says:
  - `diff --name-status -M` maps the path;
  - blob comparison;
  - symbol lookup at the target through on-demand extraction, cached by blob SHA in the `symbol` table;
  - hunk mapping, content search, and the fuzzy window of M28;
  - `searchOtherFiles` through `git grep -n -F`, at most 20 candidate files.
- `LocationCacheEntry` entity and a migration. The key is the `request.opened` event ID and the target SHA.
- An `outdated` column on `ChangeRequest`: true when the location at the default-branch head is `changed` or `orphaned`, null until computed or when the clone is gone.
- `RecomputeLocations(reviewId)`: queued for each open review after a fetch that changed `defaultBranchHead`. The handler recomputes the default-branch location of each request, updates `outdated`, and publishes `{type: "outdated", reviewId, requestIds}` with the requests whose flag changed.
- Q3 `GET /api/v1/requests/{requestId}?at=&context=` and MCP `get_request`:
  - `at` resolves with `rev-parse --verify --end-of-options <at>^{commit}`. A missing commit triggers one synchronous fetch, then 422 `commit_not_found`. An ambiguous prefix gives 422 `invalid_input`;
  - with `at`, `location.target` is `requested`. Without `at`, the location is at the default-branch head with target `default_branch`. `location` is null when the clone is gone;
  - `context` from 0 to 50 fills `excerpt`: the located region at the target with `context` lines on each side, at most 400 lines. `excerpt` is null when the location is `orphaned` or the file is not renderable;
  - a needed fetch that fails, or a gone clone with `at`, gives 409 `repository_unavailable`.
- `location` and `outdated` in the normalizers. The `outdated` filter of Q1 and `list_requests`. `Review.counts.outdated`.

## Out of scope

- UI: M30.

## Files (representative)

- `src/Anchoring/*.php`, `src/Entity/LocationCacheEntry.php`, `migrations/Version*.php`.
- `src/Message/RecomputeLocations.php`, `src/MessageHandler/RecomputeLocationsHandler.php`.
- `tests/Functional/Anchoring/LocatorFileCasesTest.php`, `LocatorRegionCasesTest.php`, `LocatorSymbolAndTargetCasesTest.php`, `LocationCacheTest.php`, `GetRequestAtTest.php`, `OutdatedFlagTest.php`.

## Tasks (ordered)

1. Build the locator on top of the M28 functions and `GitReader`.
2. Add the cache entity and run `make migration`.
3. Wire `at` and `context` into Q3 and `get_request`.
4. Add the `outdated` column, the recompute job, and the live update.

## Acceptance tests

All are functional tests on real temporary git repositories. Each case asserts the exact status, reason, and range.

- **F `LocatorFileCasesTest`** proves the file-level cases.
  - "F1: a deleted file without a rename gives orphaned file_deleted"
  - "F2: a renamed file with 50% or more similarity maps the path"
  - "F3: a renamed and much changed file is found by searchOtherFiles or gives orphaned"
  - "F7: a region moved to another file gives shifted through git grep"
- **F `LocatorRegionCasesTest`** proves the region cases.
  - "F4: lines added above the region give shifted"
  - "F5: an edited region gives changed with the mapped range and a similarity"
  - "F6: a region moved inside the file gives shifted"
  - "F8: a deleted region gives orphaned region_deleted"
  - "F9: duplicate code at equal distance gives orphaned ambiguous"
  - "F10: a trivial snapshot uses diff mapping only"
  - "F11: a whitespace or line-ending change gives exact or shifted"
- **F `LocatorSymbolAndTargetCasesTest`** proves the symbol and target cases.
  - "F12: a PHP syntax error at the target falls back to the line steps"
  - "F13: a renamed symbol gives changed or orphaned through the line steps"
  - "F14: a target commit that is not in the clone gives commit_not_found after one fetch"
- **F `LocationCacheTest`** proves the cache.
  - "A second locate of the same event and target reads the cache"
- **F `GetRequestAtTest`** proves `at` and `context`.
  - "get_request with at equals the REST body of Q3 with at"
  - "An unknown commit gives commit_not_found after one fetch"
  - "context returns an excerpt of at most 400 lines"
- **F `OutdatedFlagTest`** proves the outdated flag.
  - "A default-branch commit that edits the region sets outdated to true"
  - "The recompute publishes an outdated update with the request ID"
  - "The outdated filter finds the request"

## Verification commands

```sh
make migration
make db-test
make check
```

## Done

Every request has a location at the default-branch head, and `get_request` with `at` locates it at any commit of the server clone.
