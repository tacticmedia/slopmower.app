# F09 Dev environment and docs issues

Workflow, conventions, and status: [feature track](features.md). Hard rule: bugs and issues before features (`AGENTS.md`).

## Goal

Fix the dev environment issues that cost time in every session, and the docs that disagree with the code. Each item was checked against `main` at 26c6def.

## Items

1. **`config/reference.php` changes on every warmup.** Each cache warmup inside FrankenPHP regenerates it with a moved Mercure comment, because the bundle checks whether `mercure_publish()` exists (`vendor/symfony/mercure-bundle/src/DependencyInjection/Configuration.php:30,45-54`). The tree goes dirty after each boot, and every step restores the file by hand. Make the file stable. Find the Symfony-supported way first: the file's generator, the bundle configuration, or the Flex convention for this file.
2. **The dev worker crashes on a cache rebuild.** It crashes when the `php` container rebuilds the shared dev cache, and its message then waits for the 15-minute redelivery (`config/packages/messenger.yaml:36-43`; F04 hit it again). Make the worker survive a rebuild or restart cleanly. One option is a separate cache directory for the worker, set through the environment in `compose.yaml`.
3. **The healthcheck fills the profiler.** It writes a dev profiler entry every 30 seconds, so most stored profiles are `/healthz`. Exclude `/healthz` from the profiler.
4. **Spec text that the code does not follow:**
   - `webhooks-and-live-updates.md` section 2.2 lists "resolve pending links" before "queue recompute". The code queues the recompute first, deliberately (decision of S14, pinned by `RepositoryFetchedListenersTest`). Amend the step order in the text. This is not a wire contract.
   - `common.schema.json:30`: the lookaheads of the RepoPath pattern use `.*`. After a line terminator the schema then accepts `//`, or a `.` or `..` segment; the `RepoPath` class rejects these. Use `[^\x00]*`, as the M02 report proposed, and validate every fixture again.
   - Q2 and V7: an anchor on a non-UTF-8 text file gets 422 on `path`. The spec says only "not renderable". State the encoding rule in Q2 and in V7 `renderable`.
   - Webhooks section 1 and A1/R2: installations whose `target_type` is neither User nor Organization (enterprise) are skipped at sign-in and on `installation.created`. State it.
5. **Docs that miss operator facts:**
   - The README gotchas or architecture section 12 get the upgrade steps for a FrankenPHP release with Mercure 1.x. Today they are only in the M01 and M24 reports: `protocol_version`, the `iss`, `sub`, and `client_id` claims, the Caddyfile issuer blocks, and `cookie_name`.
   - The README gotchas: `.dockerignore` excludes `docs/user` and `docs/specs/schemas`. A production stage that copies the source must include both, or `/docs` answers 500.
   - `docs/plan/simplification.md` S12: the step text names "a header parse". The S12 report found that the duplicate was `HIGHLIGHTER_OPTIONS`. Add one sentence that says so, and leave the plan's text as it was written.

## Out of scope

Wire-contract changes. Every spec edit here makes the text match behaviour that already exists.

## Acceptance tests

- Item 1: after `make up`, a warmup in the container, and a request to the dev app, `git status` shows no change to `config/reference.php`.
- Item 2: rebuild the dev cache in the `php` container while the worker runs, then dispatch a job. The worker handles it within seconds. Record the steps in the report.
- Item 4: the fixture and schema checks pass, including the contract tests that validate fixtures.
- `make check` is green.
