# F89 Request state as jsonb

Workflow, conventions, and status: [feature track](features.md). Step 1 of the [declarative model](declarative-model.md), scheduled by Jan on 2026-10-29 ("keep going") after F88 closed the open bugs.

## Goal

`change_request` stores the folded `RequestState` in one jsonb column. A new state field needs no column, no migration, no mapper change, and no parameter of `ChangeRequest::project()`. Every contract stays as it is.

## Baseline

Measured on `43d4844` with `.claude/orchestration/metrics.sh`: src 509 PHP / 34694 lines, 509 types; tests 344 PHP / 32453 lines; assets 188 TS / 11310 lines. `App\Domain` 149 files / 5903 lines, `App\Application` 66 / 4822, `App\Controller` 72 / 3806, `App\Entity` 25 / 2716, `App\Projection` 3 / 256.

## Facts

Verified on 2026-10-29:

- `ChangeRequest` maps 28 fields, 21 of them state fields with their own column, and `project()` takes 17 parameters.
- `RequestStateMapper` (103 lines) builds the state from the getters and from the `commit_link` rows, so each of its four readers (`RequestProjector`, `RequestCommands`, `RequestQuery`, `DraftController`) runs one more query for the links.
- SQL reads these fields: `status`, `awaiting`, `suggestionState`, `outdated`, `updatedAt`, and `lastEventSeq` as columns; `claimedBy` in `UpdatesQuery`; the anchor path and start line in `RequestQuery`; `pullRequestUrls` in `ChangeRequestRepository`. Nothing reads the other fields in SQL.
- The event codecs have `PayloadReader`, `AnchorCodec`, and `TimeCodec`. `Identifier::fromString()` accepts only the base32 form of a ULID.
- `Review` has 9 event columns, and its `project()` already takes one `ReviewSnapshot`.

## Decisions

- **D98 Request state as jsonb.** `change_request.state` holds `RequestState::toPayload()`: every field except `id` and `reviewId`, which the row has. IDs in base32, times through `TimeCodec`, the anchor through `AnchorCodec`, the commit links and pull request URLs in link order. `RequestState::fromPayload()` reads it back.
- **Copies for SQL.** `status`, `awaiting` (from `AwaitingPolicy`), `suggestion_state`, `updated_at`, and `last_event_seq` stay columns, written by `ChangeRequest::project(RequestState)`. `outdated` stays a column, because it is not event data. The claim, the anchor, and the pull request URLs are read with `JSONB_PATH_TEXT` and `JSONB_PATH_INTEGER` on `state`.
- **No mapper.** `RequestStateMapper` goes. `ChangeRequest::getState()` decodes the column, so a state read needs no `commit_link` query. `new ChangeRequest(Review, RequestState)` and `project(RequestState)` write it.
- **Migration.** The migration fills `state` from the old columns and the `commit_link` rows in PHP, with `symfony/uid` for the base32 IDs, then drops the old columns. Links of one second keep the SHA order of the mapper. A later `app:projections:rebuild` writes the reducer order.
- **Review.** `Review` keeps its columns: they are few, and its `project()` already takes one value.

## Depends on

[F88](F88-open-bugs-of-candidates-and-reports.md).

## In scope

`src/Domain/Request/RequestState.php`, `src/Entity/ChangeRequest.php`, `src/Projection/RequestProjector.php`, `src/Projection/RequestStateMapper.php` (deleted), the four readers of the mapper, the getter callers, `src/Application/Query/RequestQuery.php`, `src/Application/Query/UpdatesQuery.php`, `src/Repository/ChangeRequestRepository.php`, a migration, the tests, and `docs/architecture.md` (the `ChangeRequest` row, D98).

## Out of scope

- Any change of a REST, MCP, or event contract.
- Steps 2 to 6 of the declarative model.
- `Review`, `Comment`, and `CommitLink`.

## Acceptance tests

- Unit: each state of the request scenarios equals itself after `toPayload()` and `fromPayload()`.
- Functional: a request read through `getState()` equals the fold of its events, and the filters of Q1 and V10 keep their results.
- Migration, on the dev database: the `state` of every row after the migration equals the `state` after `app:projections:rebuild`, apart from the order of links of one second.

## Verification

`make cs`, `make lint`, `make stan`, `make test`, `make front`, `make ui`, and `make e2e`. The metrics after the step, against the baseline.
