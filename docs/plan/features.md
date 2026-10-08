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
| F26 | [Blame view](F26-blame-view.md) | Done 2026-10-08, [report](reports/F26.md) | 2d1ed28 | src 435 PHP / 28733 lines, 435 types; tests 292 PHP / 26119 lines; assets 159 TS / 9201 lines; check 3m24s |
| F27 | [Symbols through tree-sitter](F27-symbols-through-tree-sitter.md) | Done 2026-10-08, [report](reports/F27.md) | 7b801a7 | src 432 PHP / 28528 lines, 432 types; tests 291 PHP / 25994 lines; assets 155 TS / 8982 lines; check 3m29s |
| F28 | [RDS authentication](F28-rds-authentication.md) | Done 2026-10-08, [report](reports/F28.md) | a551ec9 | src 428 PHP / 28337 lines, 428 types; tests 289 PHP / 25730 lines; assets 155 TS / 8977 lines |
| F29 | [Symbols for Python, Go, and Rust](F29-symbols-for-python-go-rust.md) | Done 2026-10-08, [report](reports/F29.md) | 429e2f4 | src 435 PHP / 28733 lines, 435 types; tests 292 PHP / 26147 lines; assets 159 TS / 9201 lines; check 3m3s |
| F30 | [Sign in after sign-out](F30-sign-in-after-sign-out.md) | Done 2026-10-08, replaced by F34, [report](reports/F30.md) | bd375ca | src 429 PHP / 28362 lines, 429 types; tests 289 PHP / 25747 lines; assets 155 TS / 8977 lines |
| F31 | [Directory view reload](F31-directory-view-reload.md) | Done 2026-10-08, [report](reports/F31.md) | 87042d7 | src 432 PHP / 28529 lines, 432 types; tests 291 PHP / 25995 lines; assets 155 TS / 8982 lines; check 3m41s |
| F32 | [Symbols for Java, C#, Ruby, Bash, Scala, Zig, and Lua](F32-symbols-for-more-languages.md) | Done 2026-10-08, [report](reports/F32.md) | c4cdf3e | src 435 PHP / 28740 lines, 435 types; tests 292 PHP / 26161 lines; assets 159 TS / 9201 lines; check 3m55s |
| F33 | [Twig symbols](F33-twig-symbols.md) | Done 2026-10-08, [report](reports/F33.md) | e0363d4 | src 435 PHP / 28870 lines, 435 types; tests 293 PHP / 26238 lines; assets 159 TS / 9202 lines; check 3m58s |
| F34 | [Double-submit CSRF on Twig forms](F34-double-submit-on-twig-forms.md) | Done 2026-10-08, [report](reports/F34.md) | 150b94b | src 434 PHP / 28715 lines, 434 types; tests 292 PHP / 26164 lines; assets 159 TS / 9201 lines |
| F35 | [Top-level group label](F35-top-level-group-label.md) | Done 2026-10-08, [report](reports/F35.md) | d8bd49d | src 435 PHP / 28870 lines, 435 types; tests 293 PHP / 26238 lines; assets 159 TS / 9202 lines; check 3m38s |
| F36 | [QA pipeline](F36-qa-pipeline.md) | Done 2026-10-08, [report](reports/F36.md) | bb166d4 | src 435 PHP / 28870 lines, 435 types; tests 294 PHP / 26294 lines; assets 159 TS / 9202 lines; check 3m36s |

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
- **Index version per language.** Stored symbols are keyed by blob, so a changed grammar or query of the `symbols` service leaves old rows until `app:index:rebuild --reparse`. A version per language on `indexed_blob` would re-parse stale rows on demand. A review indexed before its language was added also has none of its symbols in quick open until `app:index:rebuild` runs for that review, one review per call. From F27 and F33.
- **Go to declaration for more languages.** V14 resolves PHP names only. Other languages need their own name resolution: imports, modules, and packages. From F27.
- **Concurrent symbol requests in the index job.** The index job calls the `symbols` service once per blob, in sequence. HttpClient can keep several requests in flight. F27 measured 1.2 times the PHP time per line for files of 100 to 300 lines, and 1.5 to 1.9 times over all files, because each small file costs one round trip. From F27.
- **Symbols service address.** `symbols/src/server.ts` listens on port 3000 on all interfaces, with no authentication. Outside Compose, a firewall rule must close the port, and another program on port 3000 stops the service at start. A host and a port from the environment, for example `127.0.0.1` on a single host, remove both. From [deployment](../deployment.md).
- **Shared FQNs of accessors and overloads.** A JavaScript getter and setter, Python `@typing.overload` stubs, a `@property` with its `@x.setter`, several Go `init()` functions, and a Scala class with its companion object share one FQN, so none of them becomes a symbol anchor. Joining them into one symbol, as TypeScript overloads are joined, would make them anchorable. From F27, F29, and F32.
- **Symbol extraction edge cases.** TypeScript parameter properties (`constructor(private x)`) are no properties, while PHP promoted parameters are. A top-level `const` in an `if` block is a constant. A Python `def` in an `if` or `try` of a class body is a function with the class as parent. A Rust attribute above the doc comment is outside the range. A trailing comment at the end of the line before a declaration joins its doc comment. A Rust `impl a::S` keeps the path in `parentFqn`, so its members do not group with the struct. In the Scala 3 indentation syntax, the range of the last definition of a body runs to the next line with less indentation, so it holds the doc comment of the next definition. A Lua LDoc block of a `---` summary and `--` lines is no doc comment. Ruby `attr_reader`, `attr_accessor`, and `define_method`, Scala extension methods, and C# operators and indexers give no symbols. Twig: blocks inside a Symfony UX `{% component %}` count as blocks of the outer template, `<twig:block>` elements give no symbols, and a `{% line N %}` tag moves the lines of the symbols after it. From F27, F29, F32, and F33.
- **V7 ETag from the language map.** Each new `FileLanguage` entry changes the V7 representation and needs a new `FILE_ETAG_VERSION` (F32). A version that includes a hash of the language map would make the bump automatic. From F32.
- **More symbol languages.** Checked 2026-10-08 with web-tree-sitter 0.27.0 on Node 24.12: the npm grammar ships a `.wasm` that loads and parses without errors.
  - Data only: Java, C#, Ruby, Bash, Scala, Zig, and Lua, scheduled as F32.
  - C (`tree-sitter-c` 0.24.1): data only if `#define` maps to `constant` or `function`, because the `macro` suffix `!` holds for every language. `.h` maps to C, so a C++ header gives a parse error.
  - Small engine change: C++ (`tree-sitter-cpp` 0.23.4), where a declaration and an out-of-class definition in one file share an FQN; Elixir (`tree-sitter-elixir` 0.3.5), whose definitions are calls, so a query needs helper captures that are no kind.
  - Poor fit for the kinds: Haskell (one symbol per equation), OCaml, Julia (macros are `@m`), Terraform, CSS.
  - Twig: scheduled as F33, with the Twig lexer in PHP.
  - No usable `.wasm`: Kotlin, Swift, GraphQL, SQL, Markdown, and Vue ship none. Dart (1.0.0, 2023) and Twig (0.8.2) ship one that web-tree-sitter 0.27 refuses. Each needs a wasm build of its own. The npm name `tree-sitter-r` is a security placeholder.

- **CI maintenance.** Dependabot for the pinned action SHAs of `qa.yml`; a `concurrency` group that cancels a superseded run of the same ref; `actions/upload-artifact` v7 once act accepts it (act 0.2.89 refuses v6 and v7). From F36.
- **Entrypoint and a stale lock.** With an out-of-date `composer.lock`, the first `composer install` of the entrypoint fails in its `post-install-cmd` `composer normalize`, the container restarts, and the next start skips the install because `vendor/` is not empty, so `make up --wait` passes or fails by timing. The entrypoint could fail clearly, or `composer normalize` could run only on `update`. From F36.
- **make act on a Linux host.** The containers write root-owned files into `var/act/workspace/`, and the next `bin/act-run` cannot delete them. Docker Desktop on macOS maps the owner. From F36.

## For Jan

Decisions or actions that only Jan can take. Each comes from a report or a review, and each was checked on 2026-10-07.

- **Licensing:** a distributed build carries the Apache-2.0 obligations of `@pierre/diffs`: the licence, the NOTICE, and the `@pierre/theme` NOTICE. The repository has no notice file.
- **Upstream reports:**
  - `@pierre/diffs` blank-view bug: `finalizeRender()` compares files by identity, but `cacheKey` elsewhere. Unchanged in 1.5.2, the pinned and latest version (F18). `CodeFile.tsx` keeps the file object, so the app is not affected.
  - playwright-php 1.5.0: there is no `allTextContents()`, and `filter(['has'])` takes CSS only.
  - Symfony: a `_failure_path` that starts with "http" but is not a URL becomes a route name, and the GitHub callback answers 500.
  - Symfony 8.1: `SameOriginCsrfListener` writes the CSRF strategies of the logout request, those of the old session included, into the session that logout starts. The new session then refuses a same-origin form that sends no double-submit token. F34 makes every form send one, so the app is not affected.
  - tree-sitter-zig (`@tree-sitter-grammars/tree-sitter-zig` 1.1.2, also on master): `_container_members` uses `repeat1`, so an empty `struct {}`, `enum {}`, `union {}`, or `opaque {}` is a syntax error, and such a Zig file has no symbols (F32).
- **First GitHub Actions run:** after the push of F36, check the steps that act cannot show: the Buildx and bake layer cache, the GitHub cache service, the artifact upload, and the ownership of `var/` on the runner. Then require the workflow `QA` on `main` with branch protection.
- **GitHub App:** when a real App exists, record real API responses and webhook deliveries for the fixtures. Confirm the by-ID fetch of pending SHAs and the listing of a suspended installation. Set `GITHUB_APP_WEBHOOK_SECRET` in the vault, and the webhook content type to JSON.
- **phpstan-doctrine:** it finds a type descriptor by DBAL type class, not by type name. So `IdentifierType` and `StringValueType`, which serve several value classes, fall back to `mixed` in DQL result shapes. The fix needs either two new `phpstanApi` ignores, which the definition of done forbids, or an upstream change. F08 item 6 is left open for it.
