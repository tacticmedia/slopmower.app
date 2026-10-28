# F85 Audit fixes 4 to 6

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-10-28: "fix 4, 5 and 6", the findings 4, 5, and 6 of the repository audit of that day. They were the Candidates "Live catch-up error replaces the page", "Pending commit links never expire", and "NUL in free text gives 500".

## Goal

A failed live catch-up keeps the page and the text of an open comment box. Pending commit links that never arrive cannot slow or fail a fetch. A NUL byte in any input gives `invalid_input`, not a server error.

## Facts

Verified on 2026-10-28:

- **Catch-up.** `LiveSubscription::catchUp()` (`assets/live/useLiveRevalidation.ts:111-119`) catches a failed read of the missed events and then revalidates every loader. Offline after a wake, or a 429 or 5xx, makes the loaders throw, and the route error replaces the page. Each EventSource `open` after an error, each visibility change, and each live event start a new catch-up or revalidation.
- **Pending SHAs.**
  - `ServerClone::pendingShas()` (`src/Application/ServerClone.php:159`) passes every pending link of the repository to `BareClone::fetch()`. A pending link has no end.
  - `BareClone::fetch()` (`src/Git/BareClone.php:50-93`) fetches the pending SHAs in one command, and after a failure one SHA per command. A timeout in that step throws, and the HEAD step runs after it, so stale SHAs make a fetch slower with each link and then fail it.
  - `ResolvePendingCommitLinks` reads the pending links again after each fetch and resolves each SHA that the clone has, from any ref.
- **NUL.**
  - PostgreSQL refuses U+0000 in `text` and `jsonb`. Free-text inputs reach both: event payloads, drafts, token names, and the name lookup of a repository.
  - Only `SearchQuery` and `RequestListQuery` refuse a NUL byte. Every other REST input and MCP argument with one fails the transaction with 500 `internal_error`.
  - `ApiBodySizeListener` runs at `kernel.controller_arguments` priority -10050, before `#[MapRequestPayload]` at -10100. `ApiExceptionListener` maps `InvalidInput` to 422.
  - mcp/sdk 0.8.1 validates the arguments against the `inputSchema` with opis/json-schema before the tool runs (`CallToolHandler.php:82`) and answers -32602. Opis matches `pattern` with PCRE, so `\x00` works, as in the `RepoPath` pattern.

## Decisions

- **Catch-up.** A failed read revalidates nothing. The next open, visibility change, or live event catches up.
- **D90 Pending link fetch window.** A fetch asks the source by SHA only for the pending links of the last 7 days. An older pending link stays pending and still becomes available when its commit reaches a branch or a tag of the source. The fetch sets HEAD before the pending SHAs, and the pending step stops at the time limit without failing the fetch.
- **NUL.**
  - REST: one listener, `ApiNulByteListener`, after the body size check and before the payload mapping. It refuses a NUL byte in any query value or JSON body string with 422 `invalid_input`, field as a dotted path, for example `requestIds.0`.
  - MCP: every string parameter without another pattern gets `^[^\x00]*$`, and the pull request URL pattern becomes `^https://[^\s\x00]+$`. The SDK answers -32602.
  - `docs/specs/README.md` section 2.1 states the rule, and section 8.10 lists it as an amendment.

## Depends on

None.

## In scope

- `assets/live/useLiveRevalidation.ts` and its test.
- `src/Application/ServerClone.php`, `src/Git/BareClone.php`, and their tests.
- `src/Http/ApiNulByteListener.php` (new), `src/Mcp/McpTools.php`, and their tests.
- `docs/architecture.md` sections 4 and 11, `docs/specs/README.md` sections 2.1, 7, and 8.10, `docs/specs/mcp.md`, `docs/specs/webhooks-and-live-updates.md` sections 2.2 and 3.

## Out of scope

- A retry of a failed catch-up on a timer.
- An end or a state for a pending link older than 7 days. It stays `pending`.
- NUL bytes in route path segments. Each one is an ID, a SHA, or a `RepoPath`, whose value objects refuse it.
- The other audit findings.

## Acceptance tests

- **Vitest** `useLiveRevalidation.test.ts`: a failed read of the missed events causes no revalidation.
- **Functional** `PendingShaFetchTest`: pending SHAs that pass the time limit do not fail the fetch, and HEAD and the heads are there.
- **Functional**: a pending link older than 7 days is not fetched by SHA, and one of today is.
- **Functional** `NulByteTest`: a NUL byte in a body field, a list item, and a query value gives 422 `invalid_input` with the field, and no event.
- **Functional** MCP: `reply_to_request` with a NUL byte in `body` gives -32602 and writes nothing.

## Verification

`make cs`, `make lint`, `make stan`, `make test`, `make front`, `make ui`, and `make e2e`. A mutation check per fix.
