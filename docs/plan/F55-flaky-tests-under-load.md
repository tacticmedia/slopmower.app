# F55 Flaky tests under load

Workflow, conventions, and status: [feature track](features.md). The candidate "Flaky tests under load" (F37) became a regular failure: on 2026-10-15 and 2026-10-16, `make check` or `make act` failed three times in the UI suite while other stacks ran on the host, and each failing test passed alone.

## Goal

- `make check` and `make act` give the same result at a host load average of about 30 as on an idle host.
- A test that fails under load because of a race in the test or in the code gets the race fixed, not a longer wait.

## Facts

Verified on 2026-10-16:

- `playwright.config.ts`: `fullyParallel: true`, `retries: 0`, no `workers`, so Playwright uses half the CPU cores (8 on the 16-core host), and no `expect.timeout`, so each assertion waits 5 s.
- Failures seen:
  - 2026-10-15, `make act` at a load average of 15 to 16: 14 UI tests in `BlameView`, `ChangesTab`, `BatchReview`, `CodeNavigation`, `FileComment`, `KeyboardCommentOnLines`, `LargeFile`, and `LineComment`. Most of them timed out after 5 s on a row of the code view (`diffs-container [data-line=...]`).
  - 2026-10-16, the F54 check: `ChangesTab` "A file loads its diff when it scrolls into view". It passed 20 of 20 times alone.
  - 2026-10-16, the F53 check at a load average of 158: 16 UI tests timed out in `browserType.launch`.
  - F37: `HubIsolationTest` with an idle timeout of the Mercure hub on port 8081, and the same `ChangesTab` test, which read the blob of the last file before the scroll.
- The act job runs the same targets inside a container (`.github/workflows/qa.yml`, D62).

## Decisions

- **Find the cause of each failure first.** The implementer reproduces the load, for example with a second `make ui` or a CPU stress process in parallel, and sorts each failing test:
  - a race in the test, for example a read before the event it waits for;
  - a race in the code;
  - only slow under load.
- **Races:** fix the test or the code. No longer wait hides a race.
- **Slow only:** raise `expect.timeout` in `playwright.config.ts` to a value that the measurements justify, and set `workers` to a fixed number that the measurements justify, so a loaded host does not run 8 browsers.
- **No retries.** A retry hides a race, and the suite must stay deterministic.
- **HubIsolationTest:** find why the hub idles out under load, and fix the cause or the timeout of that test only.

## Depends on

Nothing.

## In scope

- `playwright.config.ts`, the failing specs in `tests/ui/`, `tests/Functional/Live/HubIsolationTest.php`, and the code of any race that the tests find.
- Docs: `docs/developer-guide.md` (the UI suite row, if the workers or timeouts change), `docs/plan/README.md` "Known gotchas" if a rule for new specs comes out of it, and the candidate in [features.md](features.md), which F55 closes.

## Out of scope

- The host load itself and other projects' stacks.
- Browser launch at a load average above 100. The report gives the number at which the suite still passes.

## Acceptance tests

- **Measurement in the report:** the full UI suite passes 3 times in a row under the reproduced load, and the PHP suite passes with `HubIsolationTest` under the same load. The report gives the load, the workers, the timeouts, and the run times before and after.
- Each race fix has its test, which failed before the fix under load.

## Verification

- `make check` is green, with `make act` if an `ACT_INPUTS` file changes.
