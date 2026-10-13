# F45 Database error details

Workflow, conventions, and status: [feature track](features.md). The F44 implementation found that PostgreSQL error messages carry row values into the logs (candidate "Row values in database errors"). [Architecture section 12](../architecture.md#12-risks) and the F43 privacy policy say that logs hold no code and no text of agent calls. F45 makes that true before F43 merges.

## Goal

- No PostgreSQL error detail reaches an exception message, so no log record, Messenger stamp, or response holds a row value from it.

## Facts

Verified in the code on 2026-10-14, DBAL 4.5.0, DoctrineBundle 3.3.2:

- PostgreSQL adds `DETAIL`, `HINT`, and `CONTEXT` lines to the primary message. A NOT NULL or check violation has `DETAIL:  Failing row contains (...)` with every column of the row. A unique violation has `DETAIL:  Key (...)=(...) already exists.`
- `Doctrine\DBAL\Exception\DriverException` puts the message of the driver exception into its own message and keeps the driver exception as `previous`. The driver exception keeps the `PDOException` as `previous`.
- Monolog `NormalizerFormatter::normalizeException()` writes the message of each previous exception too.
- `Driver\Middleware\AbstractDriverMiddleware::getExceptionConverter()` returns the converter that builds every `DriverException`. `PostgreSQL\ExceptionConverter` picks the class from the SQLSTATE only.
- DoctrineBundle has `#[AsMiddleware]`.

## Decisions

- **Driver middleware.** A DBAL driver middleware with `#[AsMiddleware]` wraps the exception converter. Before the PostgreSQL converter runs, it replaces the driver exception with one that has only the first line of the message, the same SQLSTATE and code, and no previous exception. So the class of each DBAL exception stays the same, and code that catches `UniqueConstraintViolationException` still works.
- **The first line stays.** Some primary messages hold an input value, for example `invalid input syntax for type uuid: "..."`. That is a parameter, not a row. The first line names the error and the column, which an operator needs.
- **Lost:** the `PDOException` and its trace as previous exceptions. The prod JSON formatter writes no trace.
- **Decisions at the merge, 2026-10-14:**
  - No `#[AsMiddleware]`. DoctrineBundle autoconfigures every `Driver\Middleware` with the tag `doctrine.middleware`, and the attribute adds only a priority or a list of connections.
  - The PostgreSQL server log writes the same `DETAIL` line and the failing statement. `docs/deployment.md` sets `log_error_verbosity = terse` and `log_min_error_statement = panic`.

## Depends on

Nothing. F44 links the candidate from the risk row of architecture section 12. F45 removes that link.

## In scope

- The middleware and its test.
- Docs: architecture section 12 (the risk "We host other developers' source code"), `docs/deployment.md` section "Database connection", and the candidate "Row values in database errors" in [features.md](features.md), which F45 closes.

## Out of scope

- `ErrorDetailsStamp` of a failed Messenger message keeps a `FlattenException` with trace arguments in the database. That is database content with the retention of F44, not a log.

## Acceptance tests

- **F** `DatabaseErrorDetailTest`: a NOT NULL violation, a check violation, and a unique violation on a table with a text column give the same DBAL exception class and SQLSTATE as before. No message in the exception chain holds the text of the row.

## Verification

- `make check` is green.
- In the dev stack, an insert that violates a NOT NULL constraint through `dbal:run-sql` shows no `DETAIL` line in the error.
