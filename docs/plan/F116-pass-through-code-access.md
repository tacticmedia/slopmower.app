# F116 Pass-through code access

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-04, after customers objected to the permanent server clone of their repositories. Jan chose three code-access modes per GitHub repository: Server copy (the clone of today), Pass-through, and Browser only ([F117](F117-browser-only-code-access.md)). The user picks one on every connect and can switch later. Each mode states its pros and cons.

## Goal

- A user connects a GitHub repository with **Server copy** or **Pass-through** and can switch between them on the repository page.
- In Pass-through, no code reaches the disk or the database. The server reads from GitHub per request and holds code in memory only during a request or job. Every feature of Server copy works.
- In every mode, a new request stores no code text.
- `docs/user/code-access.md` explains each mode with its pros and cons.

## Depends on

Nothing. F117 depends on this feature.

## Facts

Verified on 2026-11-04 on `main` at `5c2b7572`, and against GitHub with docs and live requests:

- Every code read takes a `$gitDir` from `FetchRepository::readyClonePath()` or `TenantDataPaths::clonePath()`. Readers: `GitReader`, `FileQuery`, `BlobSymbols`, `BlobStream`, and `Locator` (private `gitDir()`), about 25 call sites.
- Every sync runs `FetchRepository::request`, `fetchNow`, or `queueClone`, then `ServerClone::fetch`, then `ServerClone::synced()`. `synced()` dispatches `RepositoryFetched` to `QueueLocationRecompute` and `ResolvePendingCommitLinks`.
- The D88 access check and the suspended-installation check run only in `ServerClone::gitHubFetchCommand` (`ServerClone.php:242-299`).
- `Repository::recordFailure` returns a `fetching` repository to `idle`. Only a failed first clone becomes `failed`.
- `GitRunner::start` (cat-file) passes no secret configuration, so a lazy blob fetch in a partial clone has no token. `GitRunner::run` passes `GIT_CONFIG_*` secrets.
- `AnchorFactory::capture` writes `lines`, `before`, `after` into `review_event.payload`, `change_request.state`, and `draft.anchor`. `CarryOverController::snapshotComment` copies up to 60,000 characters of `lines` into a comment. Server logic reads `lines`, `lineCount`, `truncated`, `normalizedHash`; nothing reads `before` or `after`. The UI renders the snapshot in `AnchorSnapshot` through `snapshotRows.ts` (`RequestDetail.tsx:87`).
- `Locator` returns after `entryAt` when the blob at the mapped path equals `anchor.blobSha` (`Locator.php:162-164`). Its other git steps: `diff --name-status -M`, `cat-file`, `diff --unified=0` of two blobs, `git grep --files-with-matches` at the target.
- Q9 returns `oldBlobSha`, `oldMode`, `newMode`, `binary`, and sizes (`CommitDiffNormalizer.php:71-77`); `binary` follows null counts (`ChangedFile.php:31`). The diff view loads both sides by blob SHA through R7.
- `RateLimited` (`src/Domain/Error/RateLimited.php`, `retryAfterSeconds`) exists with TS types.
- `InstallationTokenProvider::forRepository` posts for every call (D64). Its API base is the constant `API`. `github.client` is a scoped HttpClient without retries.
- `IndexReviewHandler` records any failure as `failed`; `StalledIndexHandler` re-queues only `pending`.
- Symfony HttpClient buffers response bodies in `php://temp`, which spills to a temporary file above 2 MB. The `buffer: false` option and `HttpClientInterface::stream()` avoid it.
- GitHub:
  - `git/trees/<sha>:<dir>` answers 200 (live, not documented).
  - `commits/<ref>` with `Accept: application/vnd.github.sha` returns the full SHA and accepts a short SHA; `git/commits/<short>` answers 404.
  - `git/commits/<sha>` returns `author`, `committer`, `message`, `parents`, `tree`, no file content.
  - `git/blobs/<sha>` serves up to 100 MB, raw with `application/vnd.github.raw+json`.
  - The recursive tree answer stops at a size cap with `truncated: true`. Measured on `torvalds/linux`: 71,475 entries, 21.7 MB, 4 s.
  - `commits/<sha>` lists at most 3,000 files (300 per page). Compare lists at most 300 files.
  - GraphQL `Commit.blame(path)` returns `ranges` of `startingLine`, `endingLine`, `age`, `commit`. No ignore-revs argument is documented.
  - Tarballs drop `export-ignore` paths, so they are no complete index source.
  - Installation tokens last 1 hour and accept `repository_ids` and `permissions`. Rate limit: 5,000 to 12,500 requests per hour per installation; a 304 to an authorized conditional request does not count.
  - From Jan's machine: a 300 KB blob in 0.33 to 0.86 s, a directory tree in 1.2 s cold.
- Tests fake the GitHub HTTP API with the mock response factory `tests/Support/GitHub/GitHubResponses.php` and GitHub git with `LocalGitHub` (a `file://` bare repository). 14 test files use `LocalGitHub` clones; they stay the coverage of Server copy.

## Decisions

### D111 Code access per repository

- `Repository.codeAccess`, an enum `CodeAccess`: `clone` and `pass_through` here, `browser` in F117. Column `code_access`, not null. The migration sets `clone` on every existing row. Repository JSON field `codeAccess`.
- Local repositories are always `clone`. `ConnectRepositoryInput.codeAccess` is required for `source: github`, without a default, and must be absent or `clone` for `source: local`.
- New endpoint `POST /api/v1/repositories/{id}/code-access` with `{codeAccess}`, next to R8 in style, returns the Repository. The same value is a no-op.
  - Leaving `clone`: a Messenger job deletes the clone directory, pins included, under the repository lock, and sets `clone_size_bytes` to null. It keeps the index and the location cache, which do not depend on the mode. The state goes to `cloning` until the head sync below ends.
  - Entering `clone`: queues `CloneRepositoryJob`, state `cloning`. Reads answer `repository_unavailable` `cloning` until the clone is ready, as on connect.
  - Every switch re-queues the index job and the location recompute of the open reviews of the repository.
- `RepositorySyncHandler` and `ServerClone::fetch` decide by the current `codeAccess` when the job runs, so a job queued before a switch makes no clone of a `pass_through` repository.

### D112 Pass-through reads

- **`CodeReader`** is an interface with the read API of today's `GitReader`, bound to one repository: resolve a ref, resolve a prefix, `hasCommit`, `commitInfo`, `commitDetails`, `log`, `changedFiles`, `objectSizes`, `lsTree`, `lsTreeRecursive`, `entryAt`, `openBlob`, `readBlob`, `pathAfter`, `unifiedZeroDiff`, `filesContaining`, `blame`, plus `withLocalCopy(sha, callable)`. A resolver returns the reader of a `Repository` by its `codeAccess`. `FileQuery`, `BlobSymbols`, `BlobStream`, `Locator`, `AnchorFactory`, the controllers, `ReviewCommands`, `RequestCommands`, `RequestQuery`, `ResolvePendingCommitLinks`, `CheckRunHandler`, and `IndexReviewHandler` take a `CodeReader` or the resolver instead of a `$gitDir`.
- **`GitCodeReader`** is today's `GitReader`, `CatFileBatch`, and `StreamedBlob` code over any git directory. `withLocalCopy` calls back with itself. Behaviour of Server copy does not change.
- **`GitHubCodeReader`** uses an installation token with `CONTENTS_READ`:

  | Method | GitHub source |
  |---|---|
  | resolve, prefix | `GET repos/{o}/{r}/commits/{ref}` with `Accept: application/vnd.github.sha` |
  | `hasCommit`, `commitInfo`, `commitDetails` | `GET repos/{o}/{r}/git/commits/{sha}` |
  | `log` | `GET repos/{o}/{r}/commits?sha={ref}&per_page={n}` |
  | `lsTree`, `entryAt` | `GET repos/{o}/{r}/git/trees/{sha}:{dir}`; on a 404 or 422 for an existing directory, a walk from the root tree |
  | `lsTreeRecursive` | one `?recursive=1` call; when `truncated`, one recursive call per child tree, deeper only where truncated again |
  | `openBlob`, `readBlob` | `GET repos/{o}/{r}/git/blobs/{sha}` with `Accept: application/vnd.github.raw+json` |
  | `blame` | GraphQL `repository(owner, name) { object(oid) { ... on Commit { blame(path) { ranges } } } }`; no ignore-revs |
  | `pathAfter` | `GET repos/{o}/{r}/compare/{from}...{to}`, the `renamed` files |
  | `unifiedZeroDiff` | both blobs through `readBlob`, `git hash-object -w --stdin` into an empty scratch repository, then today's `diff --unified=0` and `UnifiedZeroDiffParser` |
  | `filesContaining` | today's `git grep` inside `withLocalCopy` |
  | `changedFiles` | `GET repos/{o}/{r}/commits/{sha}` files (status, paths, counts, new SHA) plus `entryAt` at the first parent and at the commit for the old SHA, the modes, and the sizes; the binary rule comes from the spike |
  | `objectSizes` | the `size` of the tree entries |

- **Scratch clone.** `withLocalCopy` on GitHub creates a bare repository in a new directory under `APP_SCRATCH_DIR`, fetches `git fetch --depth=1 --no-tags <url> <sha>` with the token as `GitRunner::run` secrets, calls back with a `GitCodeReader` over it, and deletes the directory in `finally`. Before the fetch, the GitHub `size` is checked against `repository_size` (D32). Only the index job and `filesContaining` use it.
- **No body on disk.** Every GitHub request of `GitHubCodeReader` sets `buffer: false` and reads the body with `stream()`. `TMPDIR` and PHP `sys_temp_dir` point at the tmpfs of `APP_SCRATCH_DIR` in the `php` and `worker` containers. In the `prod` environment the scratch code throws when `/proc/mounts` does not list `APP_SCRATCH_DIR` (or a parent) as `tmpfs`.
- **Tokens.** `InstallationTokenProvider` keeps tokens in process memory per installation, repository, and permission set until 5 minutes before `expires_at`. This amends D64.
- **Cache.** Tree listings by tree SHA and resolved commit metadata by SHA go to a cache pool with a 1-day lifetime. They hold paths and commit data only. Blob bodies are never cached.
- **Errors.** GitHub transport errors and 5xx become `RepositoryUnavailable` `fetch_failed`. A 403 or 429 with `x-ratelimit-remaining: 0` becomes `RateLimited` with the seconds until `x-ratelimit-reset`.
- **Head sync.** For `pass_through`, `ServerClone::fetch` runs the suspended-installation check and the D88 access check, reads `default_branch` from `repos/{o}/{r}` and the head SHA with `commits/{branch}`, and calls `recordSuccess`, so `synced()` dispatches `RepositoryFetched` as today. Connect, Refresh, the push webhook, review start, `get_request` with `at`, `mark_ready_for_review`, and check runs keep their flow. A head sync that fails the access or suspension check sets `failed` also from `fetching`, so `cloneReady` refuses reads until a sync passes; Refresh retries through `restartClone`.
- **Without a clone.** `PinRefs` calls run only for `clone`. No `gc`, no `count-objects`, no clone size. `ResolvePendingCommitLinks` checks every pending link of the repository with `CodeReader::hasCommit` and `commitInfo`; the D90 window stays for `clone` only. `CheckRunHandler` checks the head with `CodeReader::hasCommit`; its fork path stays for `clone`. `RequestCommands::unlinkCommit` gets the `is_dir` guard that `ReviewCommands::delete` has.
- **Index.** The path list comes from `lsTreeRecursive`. Symbols are parsed only for blobs without an `indexed_blob` row: up to 500 such blobs through `readBlob`, more inside one `withLocalCopy`. A GitHub or fetch failure is rethrown, so Messenger retries it; only a parse or extractor failure marks the index `failed`.
- **Locator.** `entryAt` through the `CodeReader` first. An unchanged blob needs no more requests. Only `filesContaining` makes a scratch clone.

### D113 Snapshots without text

- New anchors store `normalizedHash`, `lineCount`, and `truncated`, without `lines`, `before`, `after`, in every mode. `ContentSnapshot` makes the three text fields nullable. `AnchorCodec` writes them only when they are set and still decodes old events with text (D37). Old rows keep their text.
- `Locator` rebuilds the lines from `anchor.blobSha` and the range through the `CodeReader` when they are null.
- Q3 and MCP `get_request` return a snapshot with text rebuilt the same way (lines, 3 before, 3 after). When the rebuild fails, for example a blob that GitHub no longer serves, the text fields are null. Drafts and the stored state are not rebuilt.
- `AnchorSnapshot` shows "Code not available" when `lines` is null.
- `CarryOverController::snapshotComment` names the source path and lines, without code. This amends D47.

### Spike, before the rest

Run on the host with `git` and `gh api` (read-only calls to public repositories), and report the numbers in `docs/plan/reports/F116.md` before the implementation:

- a public repository of about 10,000 files with a binary file, for example `symfony/symfony`: time and directory size of `git fetch --depth=1` of one commit into a new bare repository, and of `git grep --files-with-matches` in it; the GitHub `size` of the repository next to the size of that fetch;
- the commit files entry of a changed binary file and of a large text change: which keys (`patch`, counts) tell them apart;
- whether `git/commits/{sha}` of the base repository returns the head of a pull request from a fork;
- the answer of `commits/{short}` to an ambiguous short SHA;
- the GraphQL `rateLimit { cost }` of one blame of a 3,000-line file.

When the data contradicts a decision above, stop and report before the implementation.

### Modes on the connect screen and the docs page

- `CandidateConnection` (and the onboarding `ConnectRepositoryActions`) shows two radio options without a preselection, "Server copy" and "Pass-through", each with one line, and a link "Compare modes" to `/docs/code-access`. **Connect** is disabled until one is picked.
- The repository page (`RepositoryHeader`) shows the mode with its cons in one line and a **Change** menu. Its confirmation names what happens: leaving Server copy deletes the copy of the repository; entering Server copy clones it again.
- `SyncStatus.tsx` shows "Connecting" instead of "Cloning" for a repository that is not `clone`. `LatestCommits.tsx` drops the clone wording for it.
- `docs/user/code-access.md` (new, slug `code-access`): the feature table and the pros and cons table below, what a switch does, and a privacy summary per mode. Linked from `repositories.md`, `getting-started.md`, `privacy.md`, and the connect screen.

| Feature | Server copy | Pass-through |
|---|---|---|
| File tree, file view, raw, README, diff sides | from the copy | from GitHub, per request |
| Blame | honours `.git-blame-ignore-revs` | without `.git-blame-ignore-revs` |
| Changes tab | every file | at most 3,000 files per commit |
| Symbol search, go to declaration, outline, symbol anchors | yes | yes |
| Requests follow the code | yes | yes; a rename inside a comparison of more than 300 files is not followed |

| Mode | Pros | Cons |
|---|---|---|
| Server copy | Fastest. Works during a GitHub outage and uses no GitHub rate limit for code. A force-pushed review commit stays readable. | Slopmower keeps a full copy of the repository with its history on encrypted storage until you remove the repository or Slopmower loses access. |
| Pass-through | Slopmower stores no code. Every feature works. | Each read waits for GitHub, about 0.3 to 1 s. Code does not load during a GitHub outage or when the rate limit of the installation is used up. A force-pushed review commit that GitHub deletes becomes unreadable. The server holds code in memory while it answers a request or builds the index; the first review of a large repository downloads one commit into memory. |

## In scope

- Back end: `src/Domain/Repository/CodeAccess.php` (new), `src/Entity/Repository.php`, a migration, `src/Git/` (`GitReader` to `GitCodeReader`, the `CodeReader` interface, the resolver, the scratch clone), a new `src/GitHub/GitHubCodeReader.php`, `src/GitHub/InstallationTokenProvider.php`, `src/Application/ServerClone.php`, `FetchRepository.php`, `AnchorFactory.php`, `src/Application/Query/FileQuery.php`, `BlobStream.php`, `src/Index/BlobSymbols.php`, `src/Anchoring/Locator.php`, `src/Domain/Anchor/ContentSnapshot.php`, `SnapshotCapture.php`, `src/Domain/Event/Codec/AnchorCodec.php`, the controllers that read code, `CarryOverController.php`, `ReviewCommands.php`, `RequestCommands.php`, `RequestQuery.php`, `ResolvePendingCommitLinks.php`, `CheckRunHandler.php`, `IndexReviewHandler.php`, `RepositorySyncHandler.php`, `RepositoryController.php` (connect input, code-access endpoint), the Repository normalizer, `config/packages/framework.yaml` and `cache.yaml`.
- Contracts first: `docs/specs/schemas/repository.schema.json` (`codeAccess`), `inputs.schema.json` (`ConnectRepositoryInput.codeAccess`, `CodeAccessInput`), `anchor.schema.json` (optional text fields), `docs/specs/rest-api.md` (new endpoint; notes on V7, V8, V17, R5, R7, R9, Q9), `docs/specs/README.md` (server clone, usage limits), `docs/specs/mcp.md`, fixtures, `src/Mcp/BundledSchemas.php` through `app:schema:bundle`, `assets/api/types/`.
- Front end: `CandidateConnection.tsx`, `ConnectRepositoryActions.tsx`, `RepositoryHeader` and a code-access control, `SyncStatus.tsx`, `LatestCommits.tsx`, `assets/requests/AnchorSnapshot.tsx`, `snapshotRows.ts`, the repositories route action.
- Deployment: `compose.yaml` (`tmpfs` for `APP_SCRATCH_DIR`, `TMPDIR`), `.env`, `.env.prod.dist`, `docker/frankenphp/conf.d/` (`sys_temp_dir`), `docs/deployment/README.md`, `dockerised.md`, `manual.md` (tmpfs sized at `repository_size` times the number of workers, container memory to match).
- Docs: `docs/user/code-access.md` (new, listed in `templates/docs/_navigation.html.twig`), `privacy.md` (per mode; code in memory during a request or job; old snippets kept until the review is deleted; proposal diffs and comments keep the code that people write), `terms.md`, `repositories.md`, `getting-started.md`, `account.md`, `docs/user/skill/SKILL.md` and `.claude/skills/slopmower/SKILL.md` (snapshot rebuilt), `docs/requirements.md` (lines 54, 63, 113-114, 164), `docs/architecture.md` (sections 4 and 5, D24, D26, D32, D47, D64, D90, risk row "We host other developers' source code", new D111 to D113), `docs/developer-guide.md`.

## Out of scope

- Browser only, the code-token endpoint, the browser outline: [F117](F117-browser-only-code-access.md).
- Local repositories: always Server copy.
- Changing or deleting the clones of existing repositories at deploy. They stay Server copy.
- Removing old snippet text from existing rows and events.
- GitHub Enterprise and other git hosts.
- A latency budget for Pass-through reads.

## Acceptance tests

Functional, `KernelTestCase` or `WebTestCase`, with a git-backed GitHub fake: `GitHubResponses` answers `git/trees`, `git/blobs`, `git/commits`, `commits/{ref}` (sha media type and JSON with files), commit lists, compare, and GraphQL blame by running git on the `LocalGitHub` bare repository. One unit test compares the fake's answers with real answers recorded from a public repository in `tests/Fixtures/github/`.

- One flow on a Pass-through repository through the HTTP API: connect, review start with a short SHA, tree, file, raw, blame, commit list, Q9 with a rename and a binary file, symbols, declarations, a range request, `link_commits` pending, a push webhook, the link found, `mark_ready_for_review`. After the flow, no directory exists under `APP_TENANT_DATA_DIR` for the repository, the scratch directory is empty, and no new `review_event`, `change_request`, or `draft` row holds `lines`.
- The index parses unparsed blobs through `git/blobs` up to the threshold and through one scratch clone above it (threshold lowered in the test). The fake answers `truncated` once, and the walk lists every path.
- A range request whose code moved to another file at the target is `shifted` there (F9 on Pass-through).
- A scratch clone whose callback throws leaves no directory.
- A head sync whose D88 check answers `none` sets `failed`, and a file read then answers 409.
- Switch Server copy to Pass-through: the clone directory is gone and `indexed_file` rows stay. Switch back: a clone exists after the job. A `FetchRepositoryJob` queued before the switch to Pass-through creates no clone.
- GitHub answering 403 with `x-ratelimit-remaining: 0` gives 429 `rate_limited` with `Retry-After`.
- A `request.opened` event with snapshot text still decodes and locates.
- `get_request` returns the rebuilt snapshot text; with the anchor blob gone from the fake, the text fields are null.
- Connect of a GitHub repository without `codeAccess` answers 422.

UI, on the mocked API:

- Connect is disabled until a mode is picked, and the request body carries it.
- The code-access switch on the repository page asks for confirmation and sends the new mode.
- A request whose snapshot has no lines shows "Code not available".

## Verification

- `make check` green.
- The spike numbers are in the report.
- The report lists each of the 14 `LocalGitHub` test files that changed, with the reason, and every test that the feature deleted.
- Manual run by the orchestrator on the main stack with a real GitHub App installation, after the merge: connect a repository with Pass-through; start a review; open a range request; push a change; see the outdated flag; open the Changes tab and blame; switch to Server copy and back; then `find "$APP_TENANT_DATA_DIR" -name '*.git'` lists no Pass-through repository and the scratch directory is empty.
