# F68 Latest commits on the repository page

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-19: show the latest 10 commits on the repository page, the screen that shows before a review is started or entered. It is step 2 of the onboarding (F69), "Pick a point to review".

## Goal

- The repository page lists the latest commits of the default branch.
- One click starts a review at a commit.

## Facts

Verified on 2026-10-19:

- **No endpoint lists commits, branches, or tags.** The REST index runs A1 to T3. Commit data exists only in Q9 (one linked commit), V17 (blame), and `Repository.sync.defaultBranchHead`.
- **Git primitives:**
  - `GitReader::commitInfo()` and `commitDetails()` (`src/Git/GitReader.php:88-127`) run `git log -1`. `commitDetails()` uses `--no-mailmap`, `mb_scrub`, and `new \DateTimeImmutable('@'.$unix)`.
  - `resolveCommit()` and `resolve()` (`:35-38`, `:418-426`) give 422 `commit_not_found`, or `InvalidInput` for an ambiguous prefix.
  - `RefName::fromString()` (`src/Domain/Git/RefName.php`) validates refs.
  - `RepositoryLock` (`RepositoryLock.php:10-12`) is held by fetch, clone, and clone deletion, not by reads.
- **Clone path:** `FetchRepository::readyClonePath()` (`src/Application/FetchRepository.php:165`) throws 409 `repository_unavailable` with the reasons `cloning`, `clone_failed`, and `removed` (`cloneReady()`, `:148`).
- **Repository lookup:**
  - R4 and V1 resolve `{repositoryId}` with `#[MapEntity(id: 'repositoryId')]` through the tenant filter and the repository scope of a token. A hidden ID gives 404 `not_found`.
  - An action without `#[IsGranted]` accepts S and B.
- **Bounded lists:** `docs/specs/README.md:142` says bounded lists are not paginated. V16 has `limit` 1 to 50 and no cursor.
- **Repository page:**
  - `assets/routes/repositories/RepositoryRoute.tsx` loads only V2 (`ReviewPage`, which holds `repository` with `sync.lastFetchedAt`).
  - `StartReviewForm.tsx` has a free-text `ref` (default `defaultBranch`), an optional title, and the carry-over select.
- **V1:**
  - It fetches unless the ref is a full SHA in the clone.
  - It resolves the default branch by its bare name (`ReviewCommands.php:251`).
  - `ServerClone.php:123` computes `defaultBranchHead` from `refs/heads/<branch>`.
- **Name clash:** `assets/requests/CommitList.tsx` exists, so the list type is `CommitLog`.

## Decisions

D76:
- **R9** `GET /api/v1/repositories/{repositoryId}/commits?ref=&limit=`:
  - S and B;
  - 200 `lists.schema.json#/$defs/CommitLog` `{repositoryId, ref, items}`, newest first, in the default order of `git log` through all parents;
  - not paginated.
- **Query parameters:**
  - `ref`: 1 to 255 characters, with the validation of V1. Without it, the server resolves `refs/heads/<defaultBranch>`, so the first item equals `sync.defaultBranchHead`, and `ref` is the branch name.
  - `limit`: 1 to 50, default 10.
- **No fetch:** R9 reads the clone as it is. The page shows `sync.lastFetchedAt`. R5 fetches.
- **Errors**, in the order of `README.md:84-101`:
  - 404 `not_found`;
  - 422 `invalid_input`: the `ref` syntax, `limit`, or no `ref` while there is no default branch;
  - 409 `repository_unavailable`;
  - 422 `commit_not_found`;
  - 422 `invalid_input`: an ambiguous SHA prefix.
- **Item** `commit.schema.json` `Commit`: `{sha, subject, authorName, authoredAt, committedAt}`.
  - `subject` is `%s`.
  - `authorName` has no mailmap, as in V17.
  - Invalid UTF-8 is replaced.
- **Git:**
  - New method `GitReader::log(string $gitDir, CommitSha $from, int $limit): list<LogCommit>` with `log --no-mailmap -z -n <limit> --format=format:%H%x00%an%x00%at%x00%ct%x00%s --end-of-options <sha>`.
  - The output is NUL-separated, in chunks of 5 fields.
  - The controller resolves the ref first with `resolveCommit()`, so the error mapping is the one of V1.
- **Classes:**
  - `src/Git/LogCommit.php`, schema-shaped beside `BlameCommit`;
  - `src/Application/Query/CommitLog.php`;
  - `src/Controller/Api/Input/CommitListQuery.php`.
  - The action goes in `RepositoryController`, route `api_repository_commits`. The read has one caller, so it stays in the controller.
- **UI:**
  - The loader of `RepositoryRoute` calls R9 next to V2. It skips R9 while `defaultBranch` is null. A 409 or 422 gives `commits: null` with the reason, and the page still renders.
  - `LatestCommits.tsx` has the heading "Latest commits on `<branch>`" and the line "As of the last fetch <RelativeTime>".
  - Each row has the subject on one line (the full text in `title`), the author and "committed <RelativeTime>", the short SHA with a copy button, and "Start review". The button sends intent `start` with the full SHA as `ref`, so V1 skips the fetch.
  - While the clone is not ready, the section says "Commits show when the clone is ready."
  - Page order: the header, the open reviews (when any), then "Start a review" with the ref form and the latest commits.

## Depends on

F64, which changes the review table of the repository page.

## In scope

- **Specs:**
  - `docs/specs/rest-api.md`: the index row and the R9 section after R8.
  - `docs/specs/schemas/commit.schema.json`, and `CommitLog` in `lists.schema.json`.
  - The fixture `lists.commit-list.json`: repository `01K6B2Z0R3P0S1T2V3W4X5Y6Z7`, ref `main`, and the two commits of `blame.php.json`.
  - `docs/specs/README.md`: the `commit_not_found` wording for an endpoint that does not fetch, "commits" in the bounded lists at line 142, the schema count at line 14, the rows of sections 10 and 11, and the amendment line of section 8.10.
- **Architecture:** a D76 row.
- **Back end:** `GitReader`, `LogCommit`, `CommitLog`, `CommitListQuery`, and `RepositoryController`.
- **TS:** `assets/api/types/commit.ts`, `CommitLog` in `lists.ts`, `contract-fixtures-type.test.ts`.
- **UI:** `RepositoryRoute.tsx`, the new `LatestCommits.tsx` with a CSS module, and `tests/ui/support/fakeRepositories.ts`, which answers R9 with 409 while cloning and 422 for an unknown ref.
- **Docs:** `docs/user/getting-started.md`, the step that starts a review.

## Out of scope

- A branch or tag picker, and a commit list that follows the typed ref.
- Pagination and a history view.
- Avatars and commit pages.
- A change to V1.

## Acceptance tests

- **Functional** `tests/Functional/Api/CommitListTest.php`, modelled on `BlameTest`:
  - the default branch with 10 items and the field values;
  - `ref` as a branch, a tag, and a SHA;
  - `limit=51` gives 422, and `limit=x` gives 400;
  - an unknown ref gives 422 `commit_not_found` with `{commit}`;
  - `-x` and `a..b` give 422 on `ref`;
  - a cloning and a failed clone give 409 with their reasons;
  - a commit made after the clone is absent until R5 runs;
  - a `.mailmap` does not change `authorName`;
  - a token scoped to another repository gets 404.
- **Functional** `GitReaderTest`: `log()` keeps an empty subject.
- **Functional:** `CrossTenantCases` has `api_repository_commits`, so the inventory tests pass.
- **UI** `RepositoryCommits.spec.ts`:
  - the list renders;
  - "Start review" posts V1 with the full SHA and opens the review;
  - a cloning repository shows the not-ready text;
  - a repository without a default branch has no section.

## Verification

- `make check` is green.
- `make journeys`: the repository page with commits.
