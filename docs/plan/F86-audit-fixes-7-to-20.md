# F86 Audit fixes 7 to 20

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-10-29: "fix it all", the findings 7 to 20 of the repository audit of 2026-10-28, which were Candidates. The smaller findings of the same audit are [F87](F87-smaller-audit-findings.md).

## Goal

Linked commits keep their pins under concurrency. Server clones stay small, never stay in `cloning`, and a synchronous fetch does not hold a web thread for long. One restart of the symbols service or a lost dispatch does not leave an index broken. Quick open, the file tree, rename detection, and `get_updates` do no repeated work. Agent Markdown cannot load images from other hosts. The MCP limit counts per user. The production image has no unused packages.

## Facts

Verified on 2026-10-29:

- **7 Pin race.** `RequestCommands::linkCommits()` pins before its transaction and, on failure, unpins when `isLinkedInRepository()` is false. `unlinkCommit()` and `ReviewCommands::unpinDeletedReview()` check `isLinkedInRepository()` after their transaction and unpin. No lock covers check and unpin, so an unlink that checks before a parallel link commits deletes the pin of that link. `ResolvePendingCommitLinks` pins for links that are already stored, so the check of a parallel unlink sees them.
- **8 No gc.** Every fetch of `BareClone` has `--no-auto-maintenance`, and nothing runs `git gc`. Unreachable objects count toward `repository_size` and `tenant_storage`.
- **9 Stuck clone.** `ServerClone::fetch()` catches five exception types. Any other exception, and a `GitFailed` of the HEAD step after the `try`, leaves the row in `cloning` or `fetching`. `FetchRepository::request()` restarts only `failed`.
- **10 Symbols errors.** `TreeSitterSymbolExtractor` sends `POST /v1/symbols`. The default retry strategy of HttpClient retries a transport error only for idempotent methods, and `symbols.client` has no `retry_failed`. One error fails the index job, which `IndexReviewHandler` does not retry.
- **11 Quick open.** `IndexSql::SEARCH` returns every file and symbol whose name holds the query characters in order, and `SearchRank::top()` ranks them in PHP. `useSearch()` loads on every keystroke. The members of order groups 1 and 2 are exactly the names that start with the query.
- **12 Sync fetch wait.** `FetchRepository::fetchNow()` polls the repository lock until the 60 s deadline, in the HTTP request.
- **13 Images.** `MarkdownBody` keeps `https:` image URLs of comments and READMEs, and `ShellController` sends no `Content-Security-Policy`. The SPA loads images from its own origin (V8 raw files, the build), `data:` URLs, and GitHub avatars (`avatars.githubusercontent.com`).
- **14 MCP limit.** `RateLimits::consumeMcpRequest()` keys `mcp_http` by the token ID. A user can have 50 tokens, and each `initialize` writes a session file.
- **15 Index dispatch.** `ReviewCommands::start()` dispatches `IndexReview` after the `review.started` transaction. A crash between the two leaves the index `pending` for good. A failed dispatch gives 500 for a review that exists. A dispatch inside the transaction is no fix: the e2e transport is `sync://`, so the job would run and publish before the commit.
- **16 README escape.** `readmeUrl.ts` `resolve()` calls `decodeURIComponent()` without a `try`. `%PU` is a malformed escape.
- **17 Tree reloads.** The `tree-entries` route and the `CodeLayout` route have no `shouldRevalidate`, so each live event and each action reload every expanded directory. A review commit never changes.
- **18 Rename detection.** `GitReader::pathAfter()` runs `git diff --name-status -M` over the commit range for each moved anchor. `GitReader` resets between requests and messages.
- **19 `get_updates`.** `UpdatesQuery` calls `ThreadQuery::entries()` once per returned request, up to 500 queries.
- **20 Packages.** Only `symfony/ai-mate` (dev) uses `helgesverre/toon`. Nothing uses `symfony/intl`. `hwi/oauth-bundle` requires `symfony/form`.

## Decisions

- **D91 Pin lock.** A `pins:<repositoryId>` advisory lock covers the pin and the transaction of `link_commits`, and the check and the unpin at each unpin site. It is taken before the tenant row lock and never inside it.
- **D92 Clone gc.** The asynchronous fetch job runs `git -c gc.autoDetach=false gc --auto --quiet` after the fetch and before the size measurement, under the repository lock, within the job timeout. A gc failure is logged and does not fail the fetch. Synchronous fetches and clones run no gc.
- **Clone failure.** Any exception of a clone or fetch records the failure (`The sync failed.` for an unexpected one), deletes a partial clone, and is rethrown. The HEAD step is inside the `try`.
- **Symbols retry.** `symbols.client` retries transport errors, 502, and 503 for `POST`, 4 times, after about 1, 2, 4, and 8 s.
- **Quick open.** `useSearch()` loads 150 ms after the last keystroke. `IndexSql::search()` first reads only the names that start with the query, and reads the full set only when those give fewer than `limit` hits.
- **D93 Sync fetch lock wait.** A synchronous fetch waits at most 5 s for a running fetch, then answers `fetch_failed`. The fetch itself keeps the 60 s timeout.
- **D94 Image sources.** The shell response sends `Content-Security-Policy: img-src 'self' data: https://avatars.githubusercontent.com`. A Markdown image of another host does not load.
- **MCP limit.** `mcp_http` is keyed by the user. Specs section 2.2 changes, an amendment of section 8.10.
- **D95 Index job recovery.** A scheduled job runs every hour and queues `IndexReview` again for each open review whose index is still `pending` 1 hour after the start. `start()` logs a failed dispatch and returns the review.
- **README escape.** A segment with a malformed escape stays as it is.
- **Tree.** `tree-entries` never revalidates. `CodeLayout` revalidates only when `reviewId` changes.
- **Rename detection.** `GitReader` keeps the parsed renames per `(gitDir, from, to)` until `reset()`.
- **`get_updates`.** One query reads the threads of all returned requests. `ThreadQuery::entries()` uses the same path.
- **Packages.** `helgesverre/toon` moves to `require-dev`. `symfony/intl` and the direct `symfony/form` requirement go.

## Depends on

None.

## In scope

- `src/Application/RequestCommands.php`, `src/Application/ReviewCommands.php`, `src/Git/RepositoryLock.php`.
- `src/Git/BareClone.php`, `src/Application/ServerClone.php`, `src/MessageHandler/RepositorySyncHandler.php`, `src/Application/FetchRepository.php`.
- `config/packages/framework.yaml`, `src/Index/IndexSql.php`, `assets/routes/reviews/searchData.ts`.
- `src/Controller/ShellController.php`, `src/Application/RateLimits.php`.
- `src/Schedule.php`, a new message and handler for the index recovery, `config/services.yaml` (`app.tenantless_messages`).
- `assets/review/readmeUrl.ts`, `assets/router.tsx`, `src/Git/GitReader.php`, `src/Application/Query/ThreadQuery.php`, `src/Application/Query/UpdatesQuery.php`, `src/Repository/ReviewEventRepository.php`.
- `composer.json`, `composer.lock`.
- Tests of each, and the docs: `docs/architecture.md` sections 4 and 11, `docs/specs/README.md` sections 2.2 and 8.10, `docs/specs/webhooks-and-live-updates.md` section 2, `docs/developer-guide.md`.

## Out of scope

- A name index for quick open (Candidate "Quick open on a large repository").
- A retry of a failed index by the user.
- A full Content-Security-Policy beyond `img-src`.
- The smaller findings, which are F87.

## Acceptance tests

- **7** Functional: an unlink whose check runs while a link of the same SHA holds the pin lock keeps the pin.
- **8** Functional: the fetch job packs loose objects above `gc.auto`.
- **9** Functional: an unexpected exception of a clone sets `failed` and removes the partial clone.
- **11** Functional: a query whose prefix hits fill `limit` gives the same hits as the full scan, and one with fewer prefix hits finds the full-scan hits. UI or Vitest: keystrokes within 150 ms load once.
- **12** Functional: `fetchNow()` gives `fetch_failed` after about 5 s while another holder has the lock.
- **13** Functional: the shell response has the policy.
- **14** Functional: two tokens of one user share one `mcp_http` bucket.
- **15** Functional: the recovery job queues `IndexReview` for a review pending longer than 1 hour, and not for a newer one.
- **16** Vitest: `%PUBLIC_URL%/logo.png` resolves without an error.
- **17** Vitest or UI: a revalidation does not reload the tree.
- **18** Functional: two moved anchors of the same commits run one rename detection.
- **19** Functional: `get_updates` without a cursor runs one thread query for several requests, and the result equals the per-request result.

## Verification

`make cs`, `make lint`, `make stan`, `make test`, `make front`, `make ui`, and `make e2e`. A mutation check per fix with a test.
