# M07 Event store and command service

Workflow, conventions, and status: [README](README.md).

## Goal

One write path: lock the tenant row, decide, number the events without gaps, append, project, commit, then notify.

## Depends on

M06.

## In scope

- `EventStore`: `SELECT event_seq FROM tenant WHERE id = :tenantId FOR UPDATE`, then `UPDATE tenant` and `INSERT INTO review_event` in one transaction. The tenant context comes from the application. There is no `SET LOCAL`.
- `RequestProjector` and `ReviewProjector`, in the same transaction. `ReviewProjector` keeps `Review.lastEventSeq`.
- `CommandExecutor::execute(tenantId, reviewId, decide)`: it rolls back on `DomainError`.
- An `EventsCommitted` Symfony event, dispatched only after the commit. It carries the tenant ID, the review ID, the last `seq`, and the request IDs of the transaction. M24 publishes live updates from it.
- `RequestCommands` and `ReviewCommands`: one method per command.
- `EventLogQuery`: the events of one review after a cursor.
- `app:projections:rebuild [--tenant]`: an operator command that uses `TenantFilter::suspend()` explicitly.

## Out of scope

- Git, HTTP.

## Files (representative)

- `src/EventStore/*.php`, `src/Projection/*.php`.
- `src/Application/RequestCommands.php`, `ReviewCommands.php`, `EventLogQuery.php`.
- `src/Command/RebuildProjectionsCommand.php`.
- `tests/Support/bin/append-events.php`: a script that boots the test kernel and appends events, for the concurrency test.
- `tests/Functional/EventStore/*Test.php`.

## Tasks (ordered)

1. Implement the store, the projectors, and the executor.
2. From this milestone on, every write goes through `CommandExecutor`.
3. Implement the rebuild command.

## Acceptance tests

All are functional tests.

- **F `GaplessSequenceTest`** proves the per-tenant sequence under concurrent writers. Two child processes append 25 events each at the same time.
  - "Concurrent writers produce the numbers 1 to 50 exactly once, in commit order"
- **F `RollbackTest`** proves that a failed command leaves no trace.
  - "A decider that throws leaves event_seq, the events, and the projections unchanged and dispatches nothing"
- **F `ProjectionConsistencyTest`** proves that projections are derived data only.
  - "A scenario with every event type gives the same projection snapshot before and after a rebuild"
- **F `PostCommitNotificationTest`** proves that listeners never see uncommitted rows.
  - "The listener runs only when a second connection can already read the rows"
- **F `TenantSequenceIsolationTest`** proves that tenants do not share numbers.
  - "Each tenant has its own sequence"
- **F `EventLogQueryTest`** proves review separation in the log, decision D8.
  - "Returns only one review's events after the cursor"

## Verification commands

```sh
make test
make console c="app:projections:rebuild --env=test"
make check
```

## Done

`CommandExecutor` is the only write path for events and projections.
