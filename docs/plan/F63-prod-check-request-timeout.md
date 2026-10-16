# F63 prod-check request timeout

Workflow, conventions, and status: [feature track](features.md). A bug of F50, found on 2026-10-16 by the F57 session: `bin/prod-check` hung for 30 minutes at a host load average of 60 to 70, and the rerun passed in 4 s.

## Goal

- `make prod-check` ends within a fixed time, also when FrankenPHP or the Docker daemon answers slowly.

## Facts

- `bin/prod-check` polls `GET /login` with `docker exec <container> curl ...` once per second, for at most 60 attempts.
- The `curl` has no `--max-time`, so one attempt can wait for as long as the TCP connection stays open. The counter counts attempts, not seconds, so a slow attempt makes the loop longer than 60 s.
- `bin/prod-check` is in `ACT_INPUTS`, so the `make check` of this change runs `make act`.

## Decisions

- **Each attempt has a limit:** `curl --max-time 5`. A timed-out attempt counts as "no answer yet", and the loop tries again.
- **One deadline for the loop:** the loop ends 120 s after it starts, measured with `date +%s`, not with an attempt counter. The message names the time.
- **No other change.** The checks and their order stay as they are.

## Depends on

F50.

## In scope

1. `bin/prod-check`: `--max-time 5` on the `curl` of `/login`, and a deadline of 120 s for the loop.
2. `docs/plan/reports/F63.md`.

## Out of scope

- A time limit for `docker build` and `bin/console lint:container`. The CI job has its own limit of 60 minutes.

## Acceptance tests

- `make prod-check` passes, and it still fails with a clear message when `/login` does not give 200.
- A check by hand: with `curl` pointed at a port that accepts the connection and never answers, the loop ends after about 120 s with its message.

## Verification

- `make check` with `make act` is green.
