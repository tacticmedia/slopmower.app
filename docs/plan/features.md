# Feature track

Workflow and conventions: [README](README.md). The proof of concept (M00 to M35) and the [simplification plan](simplification.md) are complete. New product work runs here, one feature at a time.

## Rules

- Hard rule: every feature request is documented in this repository before any code. [AGENTS.md](../../AGENTS.md#hard-rule-every-feature-request-is-documented) states it in full.
- A feature starts as a row in the status table and a scope file `Fnn-<name>.md` in the milestone format: Goal, Depends on, In scope, Out of scope, Acceptance tests, Verification.
- Specs first. A feature that changes a contract amends `docs/specs/` before the code, and records the decision in [architecture section 11](../architecture.md#11-decisions).
- `make check` is green at the end of a feature. One feature, one commit on `main`.
- The implementer writes `reports/Fnn.md` from the [report template](README.md#report-template). The orchestrator owns this table and the scope files.
- A feature updates the docs it makes wrong: `docs/developer-guide.md`, `docs/user/`, the skill, and the plan README conventions.
- An idea that is not scheduled goes to [Candidates](#candidates) or, when it is outside the product scope, to the [backlog](../backlog/README.md).

## Status

| # | Feature | Status | Commit | Files / lines after |
|---|---|---|---|---|
| F01 | [General requests](F01-general-requests.md) | Done 2026-10-07, [report](reports/F01.md) | b589f33 | src 380 PHP / 24850 lines, 380 types; tests 269 PHP / 23064 lines; assets 131 TS / 6668 lines; check 2m12s |
| F02 | [Focus mode](F02-focus-mode.md) | Done 2026-10-07, [report](reports/F02.md) | a1d3a37 | src 380 PHP / 24850 lines, 380 types; tests 269 PHP / 23064 lines; assets 132 TS / 6767 lines; check 2m10s |
| F03 | [Line wrapping](F03-line-wrapping.md) | Done 2026-10-07, [report](reports/F03.md) | 850f9bf | src 380 PHP / 24850 lines, 380 types; tests 269 PHP / 23064 lines; assets 132 TS / 6729 lines; check 2m9s |
| F04 | [Code navigation](F04-code-navigation.md) | Done 2026-10-07, [report](reports/F04.md) | 26c6def | src 385 PHP / 25332 lines, 385 types; tests 271 PHP / 23304 lines; assets 136 TS / 7036 lines; check 2m30s |
| F05 | [Symfony AI Mate for coding agents](F05-symfony-ai-mate.md) | Done 2026-10-07, [report](reports/F05.md) | 86d1a6b | src 380 PHP / 24850 lines, 380 types; tests 269 PHP / 23064 lines; assets 132 TS / 6767 lines; check 2m24s |
| F06 | [Back-end bugs](F06-back-end-bugs.md) | Done 2026-10-07, [report](reports/F06.md) | f0ead79 | src 387 PHP / 25460 lines, 387 types; tests 273 PHP / 23628 lines; assets 137 TS / 7276 lines; check 3m3s |
| F07 | [Front-end bugs and UX fixes](F07-front-end-bugs-and-ux-fixes.md) | Done 2026-10-07, [report](reports/F07.md) | a7cb072 | src 385 PHP / 25339 lines, 385 types; tests 271 PHP / 23316 lines; assets 137 TS / 7276 lines; check 2m43s |
| F08 | [Code-quality leftovers](F08-code-quality-leftovers.md) | Done 2026-10-07, [report](reports/F08.md) | 7241b94 | src 390 PHP / 25441 lines, 390 types; tests 274 PHP / 23692 lines; assets 137 TS / 7276 lines; check 2m48s |
| F09 | [Dev environment and docs issues](F09-dev-environment-and-docs-issues.md) | Done 2026-10-07, [report](reports/F09.md) | f9bef5b | src 385 PHP / 25339 lines, 385 types; tests 271 PHP / 23316 lines; assets 136 TS / 7036 lines; check 3m18s |
| F10 | [Carry over open requests](F10-carry-over-open-requests.md) | Done 2026-10-07, [report](reports/F10.md) | 4fd9f5a | src 401 PHP / 26168 lines, 401 types; tests 276 PHP / 24142 lines; assets 142 TS / 7626 lines; check 2m41s |
| F11 | [Quick open](F11-quick-open.md) | Done 2026-10-07, [report](reports/F11.md) | 22b8596 | src 395 PHP / 25608 lines, 395 types; tests 275 PHP / 23794 lines; assets 140 TS / 7465 lines; check 2m43s |
| F12 | [Index job memory](F12-index-job-memory.md) | Done 2026-10-07, [report](reports/F12.md) | 20cf30d | src 401 PHP / 26212 lines, 401 types; tests 277 PHP / 24268 lines; assets 142 TS / 7626 lines; check 2m36s |
| F13 | [Agent proposals](F13-agent-proposals.md) | Done 2026-10-07, [report](reports/F13.md) | dca8362 | src 413 PHP / 26933 lines, 413 types; tests 280 PHP / 24818 lines; assets 144 TS / 7963 lines; check 3m11s |
| F14 | [Pull request links](F14-pull-request-links.md) | Done 2026-10-07, [report](reports/F14.md) | dc0b5ee | src 419 PHP / 27809 lines, 419 types; tests 282 PHP / 25115 lines; assets 145 TS / 8014 lines; check 3m5s |
| F15 | [Proposal follow-ups](F15-proposal-follow-ups.md) | Done 2026-10-07, [report](reports/F15.md) | a57e1f6 | src 419 PHP / 27834 lines, 419 types; tests 283 PHP / 25157 lines; assets 146 TS / 8052 lines; check 3m42s |
| F16 | [Carry over on an open review](F16-carry-over-on-an-open-review.md) | Done 2026-10-07, [report](reports/F16.md) | d5173c6 | src 419 PHP / 27834 lines, 419 types; tests 284 PHP / 25239 lines; assets 148 TS / 8209 lines; check 3m22s |
| F17 | [Project Mate tools](F17-project-mate-tools.md) | Done 2026-10-07, [report](reports/F17.md) | 5852bb2 | src 419 PHP / 27834 lines, 419 types; tests 284 PHP / 25239 lines; assets 146 TS / 8052 lines; check 3m19s |
| F18 | [Known issues](F18-known-issues.md) | Done 2026-10-07, [report](reports/F18.md) | e62f28e | src 419 PHP / 27834 lines, 419 types; tests 284 PHP / 25239 lines; assets 148 TS / 8211 lines; check 3m9s |
| F19 | [Change the plan requirement](F19-change-the-plan-requirement.md) | Done 2026-10-07, [report](reports/F19.md) | bd46b91 | src 423 PHP / 27964 lines, 423 types; tests 285 PHP / 25409 lines; assets 149 TS / 8305 lines; check 3m2s |
| F20 | [Unlink a pull request](F20-unlink-a-pull-request.md) | Done 2026-10-07, [report](reports/F20.md) | cd2fb7e | src 427 PHP / 28311 lines, 427 types; tests 286 PHP / 25622 lines; assets 149 TS / 8313 lines; check 2m57s |
| F21 | [File view fills the window](F21-file-view-fills-the-window.md) | Done 2026-10-08, [report](reports/F21.md) | e0044e6 | src 427 PHP / 28311 lines, 427 types; tests 286 PHP / 25622 lines; assets 149 TS / 8355 lines; check 2m56s |
| F22 | [Quick open ranking](F22-quick-open-ranking.md) | Done 2026-10-07, [report](reports/F22.md) | fd0be4a | src 424 PHP / 28127 lines, 424 types; tests 286 PHP / 25522 lines; assets 149 TS / 8305 lines; check 2m52s |
| F23 | [Focus mode shortcut](F23-focus-mode-shortcut.md) | Done 2026-10-07, [report](reports/F23.md) | a526f55 | src 419 PHP / 27834 lines, 419 types; tests 284 PHP / 25239 lines; assets 149 TS / 8268 lines; check 2m54s |
| F24 | [Gutter "+" keeps the focus](F24-gutter-plus-keeps-the-focus.md) | Done 2026-10-07, [report](reports/F24.md) | db26591 | src 427 PHP / 28311 lines, 427 types; tests 286 PHP / 25622 lines; assets 149 TS / 8356 lines; check 2m45s |
| F25 | [GitHub-style code browser](F25-github-style-code-browser.md) | Done 2026-10-08, [report](reports/F25.md) | 680b78c | src 427 PHP / 28311 lines, 427 types; tests 286 PHP / 25622 lines; assets 155 TS / 8977 lines; check 3m53s |
| F26 | [Blame view](F26-blame-view.md) | In progress (2026-10-08) | | |
| F27 | [Symbols through tree-sitter](F27-symbols-through-tree-sitter.md) | Not started | | |
| F28 | [RDS authentication](F28-rds-authentication.md) | Done 2026-10-08, [report](reports/F28.md) | a551ec9 | src 428 PHP / 28337 lines, 428 types; tests 289 PHP / 25730 lines; assets 155 TS / 8977 lines |
| F29 | [Symbols for Python, Go, and Rust](F29-symbols-for-python-go-rust.md) | Not started, after F27 | | |
| F30 | [Sign in after sign-out](F30-sign-in-after-sign-out.md) | Done 2026-10-08, [report](reports/F30.md) | bd375ca | src 429 PHP / 28362 lines, 429 types; tests 289 PHP / 25747 lines; assets 155 TS / 8977 lines |

## Candidates

Ideas from review sessions, not scheduled yet. Once F01 is merged, each can also be opened as a general request in Holistic Review itself.

- **Batch thread read.** The file view makes one Q1 call per 200 requests under the path prefix and one Q3 call per request on the file, because only Q3 returns a thread. A batch read, for example `thread` on Q1 with an exact path, makes it one call. A Q1 filter by request IDs would also replace the Q3 reads of hidden reply drafts. Developer guide ledger, row "Front end".
- **Name resolution at the selection.** "Go to declaration" with a typed name resolves with the namespace and the imports at the end of the file, so a file with several namespaces uses the last one. The namespace at the selection needs the line in V14. From the F04 report.
- **Declaration suggestions.** "Go to declaration" could suggest names from V16 while typing. From the F04 and F11 reports.
- **Carry-over as a job.** V15 locates and captures in the HTTP request. A Messenger job with a live update, if a large source review shows that it is slow. From the F10 report.
- **Test suite memory.** The functional suite grows by about 50 MB over its run, to a peak of about 165 MB. A look at what each test leaves behind (kernels, clients, static caches) could lower it. `bin/phpunit --testsuite unit,functional --log-events-text <file> --with-telemetry` reproduces it. From the F18 report.
- **Quick open on a large repository.** For a one-letter query, V16 reads every name with that letter: 5,382 rows here, about 94 ms of ranking for 130,000 synthetic candidates, and a libpq result buffer outside the PHP `memory_limit`. A name index or a word-start test in SQL bounds the transfer and the time, and a cursor bounds the buffer. PostgreSQL `lower()` and `mb_strtolower()` also differ for a few characters such as `İ`. From the F22 report.
- **Last commit in the directory table.** GitHub shows the last commit message and date of each entry, and a last-commit box above the table and the file. V6 has no commit data, so this needs a new endpoint (specs first) and `git log` per entry, with a cache. From F25.
- **Line options menu.** GitHub's "Line N options" button on a selected range: "Copy lines", "Copy permalink". From F25.
- **Sticky horizontal scrollbar.** GitHub keeps a proxy of the horizontal scrollbar of the code at the window bottom. The code view has its horizontal scrollbar at the file bottom. From F25.
- **Single-child directory chains.** GitHub shows a chain of directories with one child each as one row of the directory table, for example "Test/Constraint". From F25.
- **Markdown preview of a file.** GitHub shows a `.md` file rendered, with "Preview | Code". From F25.
- **"Finish review" in the stuck header.** The review header scrolls away in the code browser, and the stuck row of the file header could show "Finish your review" while drafts exist. From F25.
- **"Blame prior to change".** GitHub's blame block links to the blame of the parent commit at the path the file had there. It needs V17 at an ancestor of the review commit and the text of the file there, and a view that leaves the pinned commit. From F26.
- **Blame avatars, commit links, and contributors.** GitHub shows the author avatar, links the summary to the commit page, and counts the contributors of the file. A local repository has no avatars and no commit page. From F26.
- **Database parameters instead of `DATABASE_URL`.** In the master-password mode of `tacticmedia/rds-auth-bundle`, the deployment injects the raw password, and a raw RDS master password can contain `%`, `#`, and `?`, which break a URL. Separate variables for the host, the port, the database name, the user, the password, and the server version avoid the encoding. From F28.
- **Pull request links after a repository rename.** A GitHub URL is stored in the canonical form of the repository name as connected (F14). After the repository is renamed on GitHub, a link under the old name no longer matches `link_pull_request` or `unlink_pull_request`. From the F20 report.
- **Index version per language.** Stored symbols are keyed by blob, so a changed grammar or query of the `symbols` service leaves old rows until `app:index:rebuild --reparse`. A version per language on `indexed_blob` would re-parse stale rows on demand. From F27.
- **Go to declaration for more languages.** V14 resolves PHP names only. Other languages need their own name resolution: imports, modules, and packages. From F27.
- **Concurrent symbol requests in the index job.** The index job calls the `symbols` service once per blob, in sequence. HttpClient can keep several requests in flight, if the index time that F27 measures calls for it. From F27.

## For Jan

Decisions or actions that only Jan can take. Each comes from a report or a review, and each was checked on 2026-10-07.

- **Licensing:** a distributed build carries the Apache-2.0 obligations of `@pierre/diffs`: the licence, the NOTICE, and the `@pierre/theme` NOTICE. The repository has no notice file.
- **Upstream reports:**
  - `@pierre/diffs` blank-view bug: `finalizeRender()` compares files by identity, but `cacheKey` elsewhere. Unchanged in 1.5.2, the pinned and latest version (F18). `CodeFile.tsx` keeps the file object, so the app is not affected.
  - playwright-php 1.5.0: there is no `allTextContents()`, and `filter(['has'])` takes CSS only.
  - Symfony: a `_failure_path` that starts with "http" but is not a URL becomes a route name, and the GitHub callback answers 500.
  - Symfony 8.1: `SameOriginCsrfListener` writes the CSRF strategies of the logout request, those of the old session included, into the session that logout starts. A same-origin form in that session then fails the CSRF check. `ResetCsrfStrategyListener` works around it (F30).
- **GitHub App:** when a real App exists, record real API responses and webhook deliveries for the fixtures. Confirm the by-ID fetch of pending SHAs and the listing of a suspended installation. Set `GITHUB_APP_WEBHOOK_SECRET` in the vault, and the webhook content type to JSON.
- **phpstan-doctrine:** it finds a type descriptor by DBAL type class, not by type name. So `IdentifierType` and `StringValueType`, which serve several value classes, fall back to `mixed` in DQL result shapes. The fix needs either two new `phpstanApi` ignores, which the definition of done forbids, or an upstream change. F08 item 6 is left open for it.
