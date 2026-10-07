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
| F08 | [Code-quality leftovers](F08-code-quality-leftovers.md) | Done 2026-10-07, [report](reports/F08.md) | | src 390 PHP / 25441 lines, 390 types; tests 274 PHP / 23692 lines; assets 137 TS / 7276 lines |
| F09 | [Dev environment and docs issues](F09-dev-environment-and-docs-issues.md) | Done 2026-10-07, [report](reports/F09.md) | f9bef5b | src 385 PHP / 25339 lines, 385 types; tests 271 PHP / 23316 lines; assets 136 TS / 7036 lines; check 3m18s |
| F10 | [Carry over open requests](F10-carry-over-open-requests.md) | In progress | | |
| F11 | [Quick open](F11-quick-open.md) | In progress | | |

## Candidates

Ideas from review sessions, not scheduled yet. Once F01 is merged, each can also be opened as a general request in Holistic Review itself.

- **One scroll area in the normal file view.** Outside focus mode, the window, the file tree, and the code view each scroll. Split from F02.
- **Project-specific Mate tools.** For example a tool that reads the event log of a review. Split from F05.

## For Jan

Decisions or actions that only Jan can take. Each comes from a report or a review, and each was checked on 2026-10-07.

- **Contract:**
  - Q9 defines no file order, so both the server and the client sort the files. Adding the order to Q9 lets the client sort go.
  - A batch thread read (for example `thread` on Q1 with an exact path) would make the file view one call instead of one Q3 per request.
- **Placement:** `Application\IssuedApiToken`, the T2 response, is the only response class outside `Application\Query`. It is a write result. Move it, or keep it.
- **Licensing:** a distributed build carries the Apache-2.0 obligations of `@pierre/diffs`: the licence, the NOTICE, and the `@pierre/theme` NOTICE. The repository has no notice file.
- **Upstream reports:**
  - `@pierre/diffs` 1.5.1 blank-view bug: `finalizeRender()` compares files by identity, but `cacheKey` elsewhere. Version 1.5.2 exists; its fix is not checked, and the version is pinned.
  - playwright-php 1.5.0: there is no `allTextContents()`, and `filter(['has'])` takes CSS only.
  - Symfony: a `_failure_path` that starts with "http" but is not a URL becomes a route name, and the GitHub callback answers 500.
- **GitHub App:** when a real App exists, record real API responses and webhook deliveries for the fixtures. Confirm the by-ID fetch of pending SHAs and the listing of a suspended installation. Set `GITHUB_APP_WEBHOOK_SECRET` in the vault, and the webhook content type to JSON.
- **phpstan-doctrine:** it finds a type descriptor by DBAL type class, not by type name. So `IdentifierType` and `StringValueType`, which serve several value classes, fall back to `mixed` in DQL result shapes. The fix needs either two new `phpstanApi` ignores, which the definition of done forbids, or an upstream change. F08 item 6 is left open for it.
