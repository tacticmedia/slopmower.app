# Simplification plan

Workflow and conventions: [README](README.md). Evidence: [developer guide](../developer-guide.md), section 7 "Complexity ledger".

## Goal

Make the code base as small and as direct as the product allows, with no change in behaviour. The guiding rule is the one of *Code Complete*: managing complexity is the primary technical imperative. An abstraction stays only when it hides real complexity. A port with one adapter, a service with one caller, a wrapper that forwards one call, or a second way to do the same thing goes.

## Hard constraints

- No change to any contract in `docs/specs/`: REST, MCP, live-update payloads, webhooks, error codes, schemas, fixtures.
- No change to behaviour. Tenant isolation stays proven: `CrossTenantCoverageTest`, `RawSqlTenantAuditTest`, the cross-tenant cases, and the SQL filter stay green.
- `make check` is green after every step. One step, one commit.
- A test that only covered a removed indirection goes with it. A test that proves behaviour stays, moved if needed.
- Each step updates `docs/developer-guide.md` and the architecture decision rows it retires. A retired decision row says "Retired by Snn" and why, in one line.
- Before each step, re-verify its evidence against the code. The ledger is a snapshot; skip or reshape a step that no longer holds, and record why.

## How to run

Use the orchestration of the plan run: one implementer per step in its own worktree, then `.claude/orchestration/review.js` with an extra lens note "simplicity first", then fixes, then `merge.sh`. The conventions lens judges each step by one question: is the result smaller and more direct, with the same behaviour? Steps marked "independent" can run in parallel.

Record a baseline before S01 and after every step: PHP and TS file count, lines per top-level namespace, class count, and `make check` time. Put the numbers in the status table below.

## Steps

| # | Step | Status | Commit | Files / lines after |
|---|---|---|---|---|
| S00 | Baseline metrics | Done 2026-10-03 | ca09c4b | src 406 PHP / 25954 lines, 406 types; tests 272 PHP / 23118 lines; assets 127 TS / 6511 lines; check 2m45s |
| S01 | Dead code | Done 2026-10-05, [report](reports/S01.md) | 1f6b805 | src 403 PHP / 25684 lines, 403 types; tests 271 PHP / 23076 lines; assets 128 TS / 6484 lines; check 2m26s |
| S02 | Inline single-consumer services | Done 2026-10-05, [report](reports/S02.md) | | src 399 PHP / 25553 lines, 399 types; tests 269 PHP / 22963 lines; assets 128 TS / 6484 lines |
| S03 | One cursor helper, one UUID helper | Not started | | |
| S04 | One DBAL type per typed ID | Not started | | |
| S05 | Split `FetchRepository`, handlers in one place | Not started | | |
| S06 | One `Anchoring` namespace | Not started | | |
| S07 | Collapse layers around commands | Not started | | |
| S08 | One serialization path | Not started | | |
| S09 | MCP tools as thin delegations | Not started | | |
| S10 | One pattern for reads | Not started | | |
| S11 | GitHub duplicates | Done 2026-10-03, [report](reports/S11.md) | a437ff1 | src 406 PHP / 25918 lines, 406 types; tests 272 PHP / 23153 lines; assets 128 TS / 6484 lines; check 2m37s |
| S12 | Front-end duplicates | Done 2026-10-03, [report](reports/S12.md) | d58d900 | src 406 PHP / 25954 lines, 406 types; tests 272 PHP / 23118 lines; assets 128 TS / 6484 lines; check 2m29s |
| S13 | Namespace cycles | Not started | | |
| S14 | Final: docs, decisions, metrics | Not started | | |

### S01 Dead code (independent)

Delete what has no production caller: `ActorCodec`, `GitReader::blobAt()`, `IndexedFileRepository`, `EventLogQuery` (test-only: move what the tests need into test support, or delete). Merge `CommitShaType` and `BlobShaType`, which differ only in names. Fold the one-enum namespaces `Domain\Draft` and `Domain\User` into their users. Check every candidate with a search for callers first.

### S02 Inline single-consumer services (independent)

`ApiTokenIssuer`, `ConnectLocalRepository`, `CommitAvailability`, `TreeQuery`: move each into its only consumer (AGENTS.md forbids a service class with one consumer). Keep each behaviour test, now against the consumer.

### S03 One cursor helper, one UUID helper (independent)

`RequestQuery`, `ReviewQuery`, `RepositoryQuery`, and `TreeQuery` each build `sortKey()` and `nextCursor` by hand; `src/Http/Cursor` already exists. Give keyset pagination one implementation. `DeletionSql`, `EventStoreSql`, `IndexSql`, `LocationCacheSql`, and `RequestQuery` each have a private `uuid()`: one shared function, or bind through the DBAL type.

### S04 One DBAL type per typed ID

Entities and repositories make 45 `Ulid::fromString()` conversions. Register one DBAL type per ID class (`TenantId`, `ReviewId`, `RequestId`, ...) so entities store and return typed IDs directly. Migrations must stay schema-neutral (`doctrine:schema:validate` and `migrations:diff` show no change).

### S05 Split `FetchRepository`, handlers in one place

`Application\FetchRepository` has 462 lines, 17 constructor parameters, and 3 message handlers, and mixes sync state, clone deletion, access removal, GitHub token requests, and local discovery. Split it by job, keeping the lock and sync rules (D24, D39). Put every Messenger handler in one place, `App\MessageHandler` (today: 4 methods in `Application`, 2 classes in `MessageHandler`, 7 methods in `Webhook\Handler`), so no message docblock needs to say where its handler is.

### S06 One `Anchoring` namespace

`App\Domain\Anchoring` (algorithms) and `App\Anchoring` (git-backed `Locator`, its only production caller) share a name. Merge or rename so one name means one thing. Keep the algorithms unit-testable without git; that is the one real gain of the split.

### S07 Collapse layers around commands

Today one action can pass through a domain command, a decider, an application wrapper (`LinkCommits`, `UnlinkCommit`, `MarkReadyForReview`), a controller, and an MCP tool. Keep what the event log needs (decide, append, project, under the tenant lock) and remove the rest: fold the wrappers into `RequestCommands` methods that do their git work outside the event transaction (D26), and drop interfaces with one implementation (for example `SymbolExtractor`). Re-examine the `src/Domain` purity rule (plan M02, domain purity test): keep it only where it makes the event model testable without the kernel, and say so in the decision row.

### S08 One serialization path

Two paths exist: 17 hand-written normalizers (1,151 lines) and DTOs that go through `ObjectNormalizer` (`RequestCommandResult`, `UpdatesPage`, `RepositoryPage`, `IndexStatus`). Pick one per kind of output, so a reader never guesses which. REST and MCP keep sharing it (D37).

### S09 MCP tools as thin delegations

12 tool classes, each one line of real work. Check what `mcp/sdk` 0.8.1 discovery supports (several `#[McpTool]` methods in one class), and reduce the tools to the smallest set of classes whose methods each delegate to one query or command. `BundledSchemas` stays generated (constant expressions are an SDK limit).

### S10 One pattern for reads

`CommitDiffController` calls `GitReader`, `Locator`, and repositories directly and sorts files itself; other reads go through `Application\Query`. Choose one pattern for reads and apply it everywhere. Prefer the more direct one when it keeps the controller short.

### S11 GitHub duplicates (independent)

The rename-collision rule exists in `InstallationSync::record()` and `RepositoryHandler::record()`; a `payload()` helper exists twice. Keep one of each.

### S12 Front-end duplicates (independent)

`REQUEST_PAGE_LIMIT` is defined twice; `CodeFile.tsx` and `CommitDiff.tsx` repeat `THEME`, `POOL_OPTIONS`, and a header parse; `RequestChanges.tsx` sorts a list the server already sorts. Remove the duplicates. `fileThreads()` reads all Q1 pages and then one Q3 per request: keep it unless the specs allow fewer calls, and record the cost.

### S13 Namespace cycles

The guide lists 13 namespace cycles. Break each one that a move or an inversion of one call removes. Record the cycles that the domain forces.

### S14 Final

Refresh `docs/developer-guide.md` (namespace map, flows, ledger with "done" marks), retire or update the architecture decision rows, run the `jk-docs-review` skill on the changed docs, and write the before-and-after metrics into this file.

## Metrics

Baseline (S00) on `main` at 4b5e79e: `make check` green in 2m45s with 1195 PHPUnit tests, 40 Vitest, 58 UI, and 8 E2E. File and line counts are of tracked files. Lines per namespace include the generated `src/Mcp/BundledSchemas.php` (2,472 lines).

| Namespace | PHP files | Lines |
|---|---|---|
| `App` | 1 | 29 |
| `App\Anchoring` | 4 | 479 |
| `App\Application` | 52 | 3914 |
| `App\Command` | 4 | 357 |
| `App\Controller` | 52 | 2265 |
| `App\Docs` | 2 | 119 |
| `App\Doctrine` | 9 | 360 |
| `App\Domain` | 113 | 4322 |
| `App\Entity` | 18 | 2265 |
| `App\EventStore` | 4 | 348 |
| `App\Git` | 15 | 1215 |
| `App\GitHub` | 9 | 529 |
| `App\Http` | 6 | 341 |
| `App\Index` | 7 | 531 |
| `App\Live` | 4 | 135 |
| `App\Mcp` | 16 | 3384 |
| `App\Message` | 13 | 286 |
| `App\MessageHandler` | 2 | 243 |
| `App\Messenger` | 2 | 89 |
| `App\Projection` | 3 | 238 |
| `App\Repository` | 19 | 1283 |
| `App\Security` | 15 | 670 |
| `App\Serializer` | 17 | 1151 |
| `App\Source` | 6 | 291 |
| `App\Tenancy` | 7 | 290 |
| `App\Webhook` | 6 | 820 |
| UI tests `tests/ui` | 41 TS | 2801 |
| Front-end unit tests `assets/**/*.test.ts` | 19 TS | 1006 |

## Done

Every step is Done or recorded as skipped with a reason, `make check` is green, the contracts are unchanged, and the metrics show the reduction.
