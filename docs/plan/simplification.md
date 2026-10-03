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
| S00 | Baseline metrics | Not started | | |
| S01 | Dead code | Not started | | |
| S02 | Inline single-consumer services | Not started | | |
| S03 | One cursor helper, one UUID helper | Not started | | |
| S04 | One DBAL type per typed ID | Not started | | |
| S05 | Split `FetchRepository`, handlers in one place | Not started | | |
| S06 | One `Anchoring` namespace | Not started | | |
| S07 | Collapse layers around commands | Not started | | |
| S08 | One serialization path | Not started | | |
| S09 | MCP tools as thin delegations | Not started | | |
| S10 | One pattern for reads | Not started | | |
| S11 | GitHub duplicates | Not started | | |
| S12 | Front-end duplicates | Not started | | |
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

## Done

Every step is Done or recorded as skipped with a reason, `make check` is green, the contracts are unchanged, and the metrics show the reduction.
