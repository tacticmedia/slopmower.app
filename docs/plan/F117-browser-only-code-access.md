# F117 Browser only code access

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-04, the third code-access mode after [F116](F116-pass-through-code-access.md): for customers who do not want their code to reach Slopmower at all. Jan asked that the product states what this mode loses.

## Goal

- A user connects a GitHub repository with **Browser only**, or switches an existing repository to it.
- In Browser only, the server never receives file content. The browser reads code straight from `api.github.com`. The server reads only metadata: trees, commits, and blame ranges.
- Features that need code on the server degrade as the docs page states, and the UI hides what does not work.

## Depends on

F116 on `main` at `d4c7d9e0` ([report](reports/F116.md)): `CodeAccess`, the `CodeReader` interface with `GitCodeReader` and `GitHubCodeReader` (HTTP in `GitHubRepositoryClient`), the resolver `src/Application/CodeReaders.php`, `src/Git/ScratchCopies.php`, `FetchRepository::readyReader()`, `Locator::withSnapshotText()`, the code-access endpoint R10, the connect choice, and `docs/user/code-access.md` with two modes.

## Facts

Verified on 2026-11-04 on `main` at `5c2b7572`, and against GitHub with live requests:

- `api.github.com` answers a CORS preflight that asks for `Authorization` with `Access-Control-Allow-Origin: *`, for REST and for `/graphql`. `raw.githubusercontent.com` rejects that preflight with 403. `codeload.github.com` allows only `https://render.githubusercontent.com`. `github.com/<repo>.git/info/refs` answers a preflight with 405 and sends no CORS header.
- The page CSP (`ShellController.php:19,34`) sets only `img-src 'self' data: https://avatars.githubusercontent.com`, so a `fetch` to `api.github.com` is not blocked. `tests/Functional/ShellRouteTest.php` asserts the exact header.
- V7 returns `FileContent` metadata (`blobSha`, `language`, `renderable`, `lineCount`, `binary`, `symlinkTarget`, `rawUrl`); `binary` and `symlinkTarget` need the blob (`FileQuery.php:64-70`). The file view then loads V8 raw text. Highlighting runs in the browser (`@pierre/diffs` worker pool). The diff view loads both sides through R7 (`changesData.ts` `useDiffSides`). README text and images use V8 (`treeData.ts`, `readmeUrl.ts`).
- The outline comes from V9 (`symbolsData.ts`), go to declaration from V14 (`declarationsData.ts`, PHP only), quick open from V16 over the index.
- `symbols/src/extract.ts` imports only types and uses `performance.now()`; it runs in a browser. `symbols/src/languages.ts` reads query files with `node:fs/promises` and loads grammars by URL; `web-tree-sitter` 0.27.0 accepts a URL or bytes. npm `tree-sitter-php` 0.24.2 ships `tree-sitter-php.wasm` and `queries/tags.scm`; `tree-sitter-twig` 0.8.2 ships `tree-sitter-twig.wasm`.
- `Locator` statuses are `exact`, `shifted`, `changed`, `orphaned`; the request list reads the stored `outdated` flag; check runs annotate located requests.
- An anchor of kind `line` or `range` requires a snapshot (`Anchor.php:41`), and `range` must match `snapshot.lineCount`.
- Installation tokens accept `repository_ids` and `permissions` and last 1 hour.

## Decisions

### D114 Browser only

- `CodeAccess::Browser`, value `browser`, for GitHub repositories only.
- **Token.** New endpoint `POST /api/v1/repositories/{id}/code-token`, session authentication, for a `browser` repository only (other modes: 409 `repository_unavailable`, reason `code_on_server`). Each call runs the suspended-installation check and the D88 access check of the signed-in user, mints an installation token with `repository_ids: [id]` and `permissions: {contents: read, metadata: read}`, and returns `{token, expiresAt, owner, name}`. Limited by the instance rate limiter to 60 calls per hour per user. The response has `Cache-Control: no-store`. The SPA keeps the token in memory only and asks again 5 minutes before `expiresAt`.
- **Server reads metadata only.** `GitHubCodeReader` in Browser only refuses `openBlob`, `readBlob`, `unifiedZeroDiff`, `filesContaining`, and `withLocalCopy`, and `changedFiles` and `pathAfter` use trees instead of endpoints that return patches. Allowed: `git/trees`, `git/commits`, commit lists, `repos/{o}/{r}`, GraphQL blame.
  - V8, R7, V9, V14 answer 409 `repository_unavailable` with the new reason `code_in_browser`.
  - V7 returns `renderable`, `lineCount`, `binary`, `symlinkTarget`, `rawUrl` as null.
  - Q9 walks the subtrees whose SHAs differ between the first parent and the commit, as `git diff-tree` does: status by blob SHA, renames only for an equal blob SHA, `binary` and the counts null.
- **Index.** `indexed_file` rows from `lsTreeRecursive`, no symbols. Quick open finds paths only.
- **Snapshot rebuild.** `Locator::withSnapshotText()` and the excerpt do not run in Browser only; `get_request` and Q3 return the anchor without snapshot.
- **Scratch leftovers (F116 follow-up).** Before `ScratchCopies` creates a copy, it deletes the directories under `APP_SCRATCH_DIR` that are older than 1 hour, so the copies of a process that died on a fatal error do not stay in memory. This applies to Pass-through.
- **Anchors.** `line` and `range` anchors have no snapshot; the range is not checked against the file length. `symbol` anchors answer 422 `invalid_input`. `get_request` has no snapshot and no excerpt; `context` is ignored.
- **Locator, metadata path.** The same blob at the same path is `exact`. The same blob at another path, found by the differing-subtree walk between the anchor commit and the target, is `shifted`. Anything else is the new status `unverified`: path and lines of the anchor, `outdated` true. Carry-over treats `unverified` like `changed`. Check runs skip `unverified`.
- **Mode switch.** Entering `browser` deletes the `symbol`, `indexed_blob`, and `location_cache_entry` rows of the repository, and the clone when the old mode was `clone`. Leaving `browser` re-queues the index. Every switch re-queues the location recompute (F116).
- **Front end.**
  - `assets/code/githubCode.ts`: the token from the endpoint, `GET https://api.github.com/repos/{o}/{r}/git/blobs/{sha}` with `Accept: application/vnd.github.raw+json`, ETag reuse, and a GitHub rate-limit answer turned into the `rate_limited` `ApiError` that `actionError` handles.
  - `fileData.ts`: in Browser only, the blob from GitHub; `renderable`, `binary`, and `lineCount` computed with the rule of `FileQuery`.
  - `changesData.ts`: diff sides from GitHub; the binary placeholder from the same rule.
  - README text and images from GitHub, images as `blob:` URLs; the CSP `img-src` adds `blob:`.
  - Outline: a worker runs `symbols/src/extract.ts` with the query files of `symbols/queries/` and grammars loaded by URL. `languages.ts` gets a loader parameter so the Node service and the browser share it. PHP and Twig use `tree-sitter-php` and `tree-sitter-twig` with new query files `php.scm` and `twig.scm`, for the browser only; the server keeps `nikic/php-parser` and the Twig lexer.
  - Hidden in Browser only: go to declaration, symbol anchors in the gutter and the symbols menu, the Raw link (replaced by a link to the file on GitHub). `AnchorSnapshot` shows "Code not available" without a snapshot.
  - The connect choice and the repository page offer the third mode.
- `docs/user/code-access.md` gets the third column and row:

| Feature | Browser only |
|---|---|
| File tree, file view, raw, README, diff sides | from GitHub, in your browser |
| Blame | without `.git-blame-ignore-revs` |
| Changes tab | no +/- counts, renames only for unchanged files |
| Outline of the open file | in your browser; PHP and Twig names can differ from the other modes |
| Symbol search, go to declaration, symbol anchors | no |
| Requests follow the code | only while the file is unchanged; a changed file shows the request on its old lines, marked outdated |
| Code snippet for your agent | no; the agent reads the file at the request's commit |

| Mode | Pros | Cons |
|---|---|---|
| Browser only | Your code never reaches Slopmower. | No symbol search, go to declaration, or symbol anchors. Requests on changed files do not move. No change counts. Agents get no snippet. A read token for the repository that lasts 1 hour sits in your browser. The same GitHub outage, rate-limit, and force-push limits as Pass-through. |

## In scope

- Contracts first: `repository.schema.json` and `inputs.schema.json` (`browser`), the code-token endpoint in `docs/specs/rest-api.md`, V7 and Q9 nullable fields, error reason `code_in_browser` and `code_on_server`, location status `unverified`, `anchor.schema.json` (snapshot optional for `line` and `range`), `docs/specs/mcp.md`, fixtures, `BundledSchemas`, `assets/api/types/`.
- Back end: `CodeAccess`, `GitHubCodeReader`, `GitHubRepositoryClient`, `CodeReaders`, `ScratchCopies`, a code-token controller, `CodeController`, `BlobController`, `SymbolController`, `DeclarationController`, `CommitDiffController`, `AnchorFactory`, `Anchor`, `Locator`, `RequestQuery`, `CarryOverController`, `CheckRunHandler`, `IndexReviewHandler`, the mode switch, `ShellController` (CSP).
- Front end: `assets/code/githubCode.ts` (new), `fileData.ts`, `changesData.ts`, `treeData.ts`, `readmeUrl.ts`, `symbolsData.ts` and a symbols worker, `FileRoute.tsx`, `GoToDeclaration`, the symbol anchor controls, `AnchorSnapshot.tsx`, the connect choice, the repository page; `symbols/src/languages.ts`, `symbols/queries/php.scm`, `twig.scm`, `package.json` (`tree-sitter-php`, `tree-sitter-twig`, latest versions checked on npm).
- Docs: `docs/user/code-access.md`, `privacy.md`, `repositories.md`, both `SKILL.md` copies (no snapshot in Browser only), `docs/architecture.md` (D114, section 5 statuses), `docs/developer-guide.md`.

## Out of scope

- Moving re-anchoring into the browser.
- A symbol index built in the browser.
- Browser only for local repositories.
- Revoking a token before it expires.

## Acceptance tests

Functional, with the git-backed GitHub fake of F116:

- A full flow on a Browser only repository (connect, review start, tree, file metadata, blame, commit list, Q9, a range request, a push, the location recompute) calls no `git/blobs`, `compare`, or `commits/{sha}` endpoint of the fake (mock transport log), and no scratch directory is created.
- The code-token endpoint mints a token for one repository with `contents: read` and `metadata: read`, answers 409 `code_on_server` for a Pass-through repository, and refuses a user without access.
- V8, R7, V9, and V14 answer 409 `code_in_browser`; V7 answers with null `renderable`, `lineCount`, `binary`, `symlinkTarget`.
- Metadata locate: an unchanged file gives `exact`, the same blob under a new path gives `shifted`, a changed file gives `unverified` with `outdated` true.
- Q9 on a commit that changes one file in a deep directory requests only the trees on that path (mock transport log), and reports a rename with an equal blob SHA.
- A `symbol` anchor answers 422 on a Browser only repository.
- A scratch directory older than 1 hour is gone after the next scratch copy; a younger one stays.
- Switch Server copy to Browser only: no clone directory, no `symbol` and `indexed_blob` rows of the repository.

UI, on the mocked API with `api.github.com` routed by Playwright:

- Connect offers three modes and sends `browser`.
- The file view of a Browser only repository loads the blob from `api.github.com` with the token, and shows the outline from the worker.
- The diff view loads both sides from `api.github.com`.
- Go to declaration and symbol anchors are absent; the Raw link points to GitHub.
- A request without a snapshot shows "Code not available".
- A GitHub rate-limit answer shows the rate-limit message.

## Verification

- `make check` green.
- Manual run by the orchestrator on the main stack with a real GitHub App installation, after the merge: connect a repository with Browser only; open files, README, blame, the Changes tab, the outline of a PHP and a TypeScript file; open a range request; push a change; the request shows outdated. The browser network log shows file content only from `api.github.com`; the profiler HTTP client panel shows only metadata endpoints on the server.
