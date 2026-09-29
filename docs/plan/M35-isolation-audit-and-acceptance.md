# M35 Isolation audit and acceptance

Workflow, conventions, and status: [README](README.md).

## Goal

Prove tenant isolation and contract coverage across the whole application, and run the proof of concept by hand with a real agent.

## Depends on

All earlier milestones.

## In scope

- Cross-tenant coverage of every surface: `/api/v1` routes, MCP tools, Mercure topics, tenant file paths, and the public documentation routes, which must expose no tenant data.
- `SchemaCoverageTest`: every schema in `docs/specs/schemas/` is asserted by at least one test. Use a static scan of the tests, or a record that `JsonApiAssertions` writes during the run.
- `RawSqlTenantAuditTest` of M07 passes over the whole code base.
- The E2E suite is green three times in a row. Fix flaky tests by waiting for conditions, never by sleeping.
- A manual run of [docs/user/local-run.md](../user/local-run.md) and [docs/user/connect-your-agent.md](../user/connect-your-agent.md) with Claude Code on a repository under `~/dev`: sign in locally, connect the repository, start a review, comment, let the agent work, verify, reopen once, close the review.
- The backlog is complete: every TODO in the code has an entry in `docs/backlog/`. Deviations from the architecture are recorded in docs/architecture.md.

## Out of scope

- Operations. Jan handles production, backups, monitoring, and CI.

## Files (representative)

- `tests/Functional/Audit/CrossTenantCoverageTest.php`, `SchemaCoverageTest.php`.
- `docs/architecture.md`: recorded deviations.
- `docs/backlog/README.md` and existing backlog files: new entries only.

## Tasks (ordered)

1. Write the two audit tests. Close every gap that they and `RawSqlTenantAuditTest` find.
2. Run the E2E suite three times in a row and fix what is flaky.
3. Do the manual acceptance run. Record each step and its result in the report.
4. Record the deviations and complete the backlog entries.

## Acceptance tests

All are functional tests.

- **F `CrossTenantCoverageTest`** proves isolation on every surface.
  - "Every /api/v1 route, MCP tool, Mercure topic builder, and tenant file path has a cross-tenant case"
  - "The public documentation routes return no tenant data"
- **F `SchemaCoverageTest`** proves contract coverage.
  - "Every schema in docs/specs/schemas is asserted by at least one test"

## Verification commands

```sh
make check
make check
make check
```

## Done

Jan accepts the proof of concept.
