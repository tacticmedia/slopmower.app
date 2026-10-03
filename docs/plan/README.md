# Implementation plan: Holistic Review proof of concept

This plan splits the proof of concept into 38 milestones: M00 to M35, plus M34b and M34c. One Claude session implements one milestone, writes a report, and stops.

The plan is complete. All 38 milestones are committed on `main`, one squash commit per milestone. On Jan's instruction, an orchestrator session ran the milestone sessions in git worktrees, reviewed each result, and made the commits. The [status table](#status) gives the commit date of each milestone.

- The headless slice is complete at M16. The full review flow then works through REST and MCP, without a browser.
- The vertical slice is complete at M22. The same flow then works in the browser.
- GitHub sign-in, GitHub App repositories, and GitHub webhooks are part of the proof of concept: M32 to M34.

The plan covers only the local development environment. Jan handles operations: production, backups, monitoring, and CI.

## Sources of truth

Highest first:

1. Jan's instructions in the session.
2. [docs/specs/](../specs/README.md): the REST and MCP contracts, the JSON Schemas in `docs/specs/schemas/`, the fixtures in `docs/specs/fixtures/`, the error codes, the tool signatures, and the webhook and live-update payloads.
3. [docs/architecture.md](../architecture.md) with its appendices in [docs/architecture/](../architecture/spike-results.md), and [docs/requirements.md](../requirements.md).
4. This plan.
5. [AGENTS.md](../../AGENTS.md): the Symfony conventions.

When two sources conflict, follow the higher source and record the difference in the report. Stop and report when no source decides the question, or when a source contradicts itself. When a task needs a wire format that docs/specs does not define, stop and report. Never invent a contract.

The spec files that milestones name:

| File | Content |
|---|---|
| [docs/specs/README.md](../specs/README.md) | Conventions: formats, authentication, actors, error model and check order, pagination, ETags, repository sources, versioning |
| [docs/specs/rest-api.md](../specs/rest-api.md) | The endpoint index with the IDs A1 to T3 in section 2, and the account, repository, review, and code endpoints |
| [docs/specs/rest-api-requests.md](../specs/rest-api-requests.md) | Requests, updates, drafts, API tokens, and the routes outside `/api/v1` |
| [docs/specs/mcp.md](../specs/mcp.md) | Every MCP tool, the server `instructions`, the error mapping |
| [docs/specs/webhooks-and-live-updates.md](../specs/webhooks-and-live-updates.md) | GitHub webhooks, fetch triggers, Mercure topics and payloads |
| `docs/specs/schemas/<name>.schema.json` | JSON Schemas, draft 2020-12, `$id` base `https://holistic-review.invalid/schemas/v1/` |
| `docs/specs/fixtures/` | Example payloads. PHP contract tests and TypeScript type checks use the same files. |

## Precondition

- Before M00, all of `docs/` is committed, and `git status --porcelain` shows nothing.
- docs/specs/ exists, with its README, its schemas, and its fixtures.
- The host has these tools:
  - Docker Desktop with Compose v2;
  - git and make;
  - PHP 8.5 and Composer, for M00 and the M01 baseline;
  - Node.js 24 LTS with npm, from M17 on;
  - Claude Code, for the manual checks in M15, M16, and M35.

## Start a session

1. Read this README completely.
2. Work on the milestone that Jan assigned. Without an assignment, find it in the status table:
   - the first milestone with the status "In progress" continues a split. Its Notes column names the tasks that are done;
   - else the first milestone with the status "Not started";
   - a milestone with the status "Blocked" needs a decision from Jan. Stop and ask.
3. Read the milestone file completely. Read the architecture sections and the docs/specs files that it names.
4. Run `git status`. An earlier milestone can be uncommitted while it waits for its commit. If you find changes that no earlier report explains, stop and ask Jan.
5. Check the baseline. If it is red, stop and report. Do not fix unrelated failures silently.
   - M00: `composer validate --no-check-publish` and `bin/console lint:container` on the host;
   - M01: the M00 verification commands on the host;
   - from M02 on: `make up`, `make db-test`, and `make check`.
6. Set the status of the milestone to "In progress".
7. Do the tasks in order. Write each acceptance test together with the code that it proves, or before it.
8. Run the verification commands of the milestone, then `make check`.
9. Set the status to "Done (awaiting commit)" or "Blocked", with the date. Write the report from the [template](#report-template) into `reports/MNN.md`. Then stop.

When an orchestrator runs the sessions, it owns the status table, and the sessions do not edit it.

## Workflow rules

- One milestone per session. Never start the next milestone.
- The scope is the "In scope" list of the milestone. Put everything else in the report under "Follow-ups".
- If a milestone is clearly too large for one session, stop at a consistent point with all tests green. Report, propose a split, keep the status "In progress", and write the done tasks into the Notes column.
- Stop and report when:
  - the baseline is red;
  - docs/specs is missing or contradicts itself;
  - a pinned package behaves differently from this plan;
  - a task needs a decision that no document makes.
- Destructive operations need Jan's approval first:
  - `git reset`, `git clean`, `git checkout -- <path>`, `git restore`;
  - dropping the dev database, `make db-reset`;
  - deleting Docker volumes, `docker compose down --volumes`;
  - deleting files that the current milestone did not create, except the deletions that the milestone lists.
- Never commit, push, amend, or stash, unless Jan permits it in the session. Jan commits, or an orchestrator on his instruction.
- Never restate or re-emit a prompt, an instruction, or an earlier message, in full or reworded, and never quote a refusal message back into the session. To carry state across a compaction, write it into the status table and into `reports/`, then continue from those files. State lives in files, not in repeated text.
- No Python. Scripts are PHP or shell.
- Use context7 to read the documentation of a library before you use its API. When context7 has no answer, read the installed source under `vendor/` or `node_modules/`.
- Before each install, check the latest version on Packagist or npm. Install the version from the [verified versions](#verified-versions) table. If a newer version exists, report it. Jan decides about upgrades. A package that the table does not list gets its latest stable version, and the report names it.
- A milestone never creates a file in `docs/backlog/`. It may add an entry to an existing backlog file and to [docs/backlog/README.md](../backlog/README.md).
- A TODO in code needs a backlog entry.

## Status

Status values:

- "Not started", "In progress", "Blocked": the session sets them, or the orchestrator that runs it.
- "Done (awaiting commit)": the work is complete and not committed yet.
- "Done": the milestone is committed on `main`. Whoever commits it sets "Done" and the commit date in this table and in the report.

All 38 milestones are "Done". The Date column holds the date of the milestone commit on `main`, from `git log main --format='%h %ad %s' --date=short`.

| # | Milestone | Status | Date | Notes |
|---|---|---|---|---|
| M00 | [Baseline packages and quality gates](M00-baseline-packages-and-quality-gates.md) | Done | 2026-10-02 | [Report](reports/M00.md) |
| M01 | [Local Docker environment](M01-local-docker-environment.md) | Done | 2026-10-02 | [Report](reports/M01.md) |
| M02 | [Domain value objects and errors](M02-domain-value-objects-and-errors.md) | Done | 2026-10-02 | [Report](reports/M02.md) |
| M03 | [Domain events and payload codec](M03-domain-events-and-payload-codec.md) | Done | 2026-10-02 | [Report](reports/M03.md) |
| M04 | [Request lifecycle](M04-request-lifecycle.md) | Done | 2026-10-02 | [Report](reports/M04.md) |
| M05 | [Tenancy persistence and isolation](M05-tenancy-persistence-and-isolation.md) | Done | 2026-10-02 | [Report](reports/M05.md) |
| M06 | [Review data schema](M06-review-data-schema.md) | Done | 2026-10-02 | [Report](reports/M06.md) |
| M07 | [Event store and command service](M07-event-store-and-command-service.md) | Done | 2026-10-02 | [Report](reports/M07.md) |
| M08 | [Git plumbing](M08-git-plumbing.md) | Done | 2026-10-02 | [Report](reports/M08.md) |
| M09 | [Local repository source](M09-local-repository-source.md) | Done | 2026-10-02 | [Report](reports/M09.md) |
| M10 | [Sign-in and security](M10-sign-in-and-security.md) | Done | 2026-10-02 | [Report](reports/M10.md) |
| M11 | [REST foundation and repositories API](M11-rest-foundation-and-repositories-api.md) | Done | 2026-10-02 | [Report](reports/M11.md) |
| M12 | [Reviews API](M12-reviews-api.md) | Done | 2026-10-02 | [Report](reports/M12.md) |
| M13 | [Requests API, user side](M13-requests-api-user-side.md) | Done | 2026-10-02 | [Report](reports/M13.md) |
| M14 | [API tokens](M14-api-tokens.md) | Done | 2026-10-02 | [Report](reports/M14.md) |
| M15 | [MCP server and read tools](M15-mcp-server-and-read-tools.md) | Done | 2026-10-02 | [Report](reports/M15.md) |
| M16 | [MCP write tools and headless slice](M16-mcp-write-tools-and-headless-slice.md) | Done | 2026-10-03 | Headless slice, [Report](reports/M16.md) |
| M17 | [Front-end shell](M17-front-end-shell.md) | Done | 2026-10-02 | [Report](reports/M17.md) |
| M18 | [Repositories and reviews screens](M18-repositories-and-reviews-screens.md) | Done | 2026-10-02 | [Report](reports/M18.md) |
| M19 | [Settings: API tokens](M19-settings-api-tokens.md) | Done | 2026-10-02 | [Report](reports/M19.md) |
| M20 | [Code view](M20-code-view.md) | Done | 2026-10-02 | [Report](reports/M20.md) |
| M21 | [Commenting UI](M21-commenting-ui.md) | Done | 2026-10-03 | [Report](reports/M21.md) |
| M22 | [Verify UI and vertical slice](M22-verify-ui-and-vertical-slice.md) | Done | 2026-10-03 | Vertical slice, [Report](reports/M22.md) |
| M23 | [Public docs and downloadable skill](M23-public-docs-and-downloadable-skill.md) | Done | 2026-10-03 | [Report](reports/M23.md) |
| M24 | [Live updates via Mercure](M24-live-updates-via-mercure.md) | Done | 2026-10-03 | [Report](reports/M24.md) |
| M25 | [Drafts, batch review, viewed files](M25-drafts-batch-review-viewed-files.md) | Done | 2026-10-03 | [Report](reports/M25.md) |
| M26 | [Symbol index](M26-symbol-index.md) | Done | 2026-10-03 | [Report](reports/M26.md) |
| M27 | [Symbol anchors and keyboard access](M27-symbol-anchors-and-keyboard-access.md) | Done | 2026-10-03 | [Report](reports/M27.md) |
| M28 | [Re-anchoring core](M28-re-anchoring-core.md) | Done | 2026-10-02 | [Report](reports/M28.md) |
| M29 | [Re-anchoring with git, locations, outdated](M29-re-anchoring-with-git-locations-outdated.md) | Done | 2026-10-03 | [Report](reports/M29.md) |
| M30 | [Verify view diffs and outdated UI](M30-verify-view-diffs-and-outdated-ui.md) | Done | 2026-10-03 | [Report](reports/M30.md) |
| M31 | [Data deletion and tenant purge](M31-data-deletion-and-tenant-purge.md) | Done | 2026-10-03 | [Report](reports/M31.md) |
| M32 | [GitHub sign-in](M32-github-sign-in.md) | Done | 2026-10-02 | [Report](reports/M32.md) |
| M33 | [GitHub App repositories](M33-github-app-repositories.md) | Done | 2026-10-02 | [Report](reports/M33.md) |
| M34 | [GitHub webhooks](M34-github-webhooks.md) | Done | 2026-10-03 | [Report](reports/M34.md) |
| M34b | [Mocked UI suite](M34b-mocked-ui-suite.md) | Done | 2026-10-03 | [Report](reports/M34b.md) |
| M34c | [Full-stack suite on Playwright](M34c-full-stack-suite-on-playwright.md) | Done | 2026-10-03 | [Report](reports/M34c.md) |
| M35 | [Isolation audit and acceptance](M35-isolation-audit-and-acceptance.md) | Done | 2026-10-03 | [Report](reports/M35.md) |

## Order

Jan assigns the milestones. Without an assignment, sessions run them in number order. A milestone can start when the milestones in its "Depends on" section are done. The "Depends on" section of each milestone file names the earlier work that it builds on. The orchestrator started independent milestones in parallel worktrees, so the commit order on `main` differs from the number order.

## Environment and commands

PHP, Composer, the console, and PHPUnit run in the `php` container. `make` wraps `docker compose exec -T php`. npm and the Vite dev server run on the host. M00 and the M01 baseline use host PHP and Composer, because the container arrives in M01.

| Command | Does |
|---|---|
| `make help` | Lists the targets. The default target. |
| `make up` | `docker compose up -d --wait --build`: services `php`, `worker`, `database` |
| `make down` | `docker compose down`, without `--volumes` |
| `make sh` | Shell in the `php` container |
| `make worker-restart` | `bin/console messenger:stop-workers`. Compose restarts the worker with the new code. Run it after a PHP change that the worker uses. |
| `make console c="<arguments>"` | `bin/console <arguments>` |
| `make composer c="<arguments>"` | `composer <arguments>` |
| `make migration` | `bin/console make:migration --no-interaction` |
| `make db-reset` | Dev database: drop, create, migrate. Ask Jan first. |
| `make db-test` | Databases `app_test` and `app_e2e`: create each one if it is missing, then migrate it |
| `make test` | `bin/phpunit --testsuite unit,functional`. Runs `npm run build:e2e` first when `package.json` exists and `public/build-e2e/entrypoints.json` is missing. |
| `make e2e` | `npm run build:e2e` on the host when `package.json` exists, then in the `php` container: `npm install --save-exact playwright@$PLAYWRIGHT_VERSION` for the playwright-php server, `vendor/bin/playwright-install chromium` (returns at once when the browser is present), and `bin/phpunit --testsuite e2e` |
| `make stan` | `cache:warmup --env=test`, then `vendor/bin/phpstan analyse` at level 8 on the test container, so the dev worker keeps its cache |
| `make cs` | `vendor/bin/php-cs-fixer fix --dry-run --diff` |
| `make cs-fix` | `vendor/bin/php-cs-fixer fix` |
| `make lint` | `lint:container`, `lint:yaml config`, `lint:twig templates`, `doctrine:schema:validate --env=test` |
| `make front` | `npm run typecheck && npm test` on the host when `package.json` exists |
| `make ui` | On the host when `package.json` exists: `npx playwright install chromium` (returns at once when the browser is present), then `npm run test:ui`: Playwright against the mocked API, on `UI_PORT` |
| `make check` | `cs`, `stan`, `lint`, `test`, `front`, `ui`, `e2e` |

For verbose PHPUnit output, use `--debug`. Never use `-v` or `--verbose`. Example: `docker compose exec php bin/phpunit --debug --filter StartReviewTest`.

## URLs and environment variables

The host ports 8000 and 5432 are the defaults of `HTTP_PORT` and `DATABASE_PORT`.

| What | Where |
|---|---|
| App | `http://localhost:8000`: `dev` environment, FrankenPHP worker mode, Mercure hub at `/.well-known/mercure` |
| E2E site | `http://localhost:8081`: `e2e` environment, classic mode, reachable only inside the `php` container |
| PostgreSQL | `127.0.0.1:5432`, databases `app` for `dev`, `app_test` for `test`, and `app_e2e` for `e2e` |
| Vite dev server | `npm run dev` on the host. The `dev` shell pages need it running, or a build from `npm run build`. |
| MCP endpoint | `http://localhost:8000/_mcp` |
| Public documentation | `http://localhost:8000/docs`, raw skill at `http://localhost:8000/docs/skill/SKILL.md` |

| Variable | Set in | Meaning |
|---|---|---|
| `HTTP_PORT`, `DATABASE_PORT` | `.env.make.local` (git-ignored), read by the `Makefile` | Host ports of `php` and `database`. Default 8000 and 5432. A second checkout, for example a git worktree, sets its own ports. Each line needs `export`, for example `export HTTP_PORT=8101`, or make does not pass it to compose. |
| `UI_PORT` | `.env.make.local`, exported by the `Makefile`, read by `playwright.config.ts` | Port of the `vite preview` server of the UI suite. Default 4173. A second checkout sets its own, for example `export UI_PORT=4101`. |
| `LOCAL_REPOSITORIES_HOST_DIR` | Host shell | Host directory that compose mounts read-only at `/repositories`. Default `./var/local-repositories`. |
| `APP_LOCAL_REPOSITORIES_DIR` | `.env` files | Empty in `.env`, which means that local repositories are off. `/repositories` in `.env.dev`, `var/test/local-repositories` in `.env.test` and `.env.e2e`. It is an instance feature: every tenant of the instance may connect the repositories under it. |
| `APP_LOCAL_SIGN_IN` | Host shell, passed through compose | Login of the one local user. Honoured only when `APP_ENV=dev`. |
| `APP_TENANT_DATA_DIR` | `.env` files | Root of the tenant files, for example the clones: the `<data>/tenants` directory of docs/specs. `/srv/tenants` in `.env.dev`, on the `tenant_data` volume. `var/test/tenants` in `.env.test` and `.env.e2e`. |
| `DEFAULT_URI` | `.env` | Base of the Mercure topics, from M24 on. Not the base of the links in `/api/v1/me`, which come from the request. |
| `APP_PUBLIC_HOST` | `.env` | Host that the MCP transport accepts, from M15 on |
| `LOCK_DSN` | `.env`, with the host `database` | Store of `symfony/lock`: PostgreSQL advisory locks, from M08 on |
| `MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_JWT_SECRET` | `.env` files. Also `MERCURE_URL` in the compose `worker` service, because the hub runs in the `php` container, and `MERCURE_JWT_SECRET` in the compose `php` service, because Caddy reads only the container environment. | Hub URL for the publisher, hub URL for the browser, JWT secret |
| `GITHUB_APP_CLIENT_ID`, `GITHUB_APP_SLUG` | Empty in `.env`, real values in `.env.local`, fake values in `.env.test` and `.env.e2e` | GitHub App identity, from M32 on. Empty means that no GitHub App is configured. |
| `GITHUB_GIT_BASE_URL` | `.env`, default `https://github.com` | Base URL for GitHub clone and fetch, from M33 on. Tests point it at a local bare repository. The token extraheader stays bound to `https://github.com`, so another base URL never receives the token. |
| `GITHUB_APP_CLIENT_SECRET`, `GITHUB_APP_PRIVATE_KEY`, `GITHUB_APP_WEBHOOK_SECRET` | Secrets vault or `.env.local`, fake values in `.env.test` | GitHub App credentials: the client secret from M32 on, the private key from M33 on, the webhook secret from M34 on. Read with `%env(default::<NAME>)%`, not with a default in `.env`, because a non-empty `.env` value wins over the vault. |
| `PLAYWRIGHT_BROWSERS_PATH` | `Dockerfile` | `/ms-playwright`, on the `playwright_browsers` volume. `vendor/bin/playwright-install` installs Chromium there. |
| `PLAYWRIGHT_VERSION` | `Dockerfile` | `1.63.0`: the `playwright` npm version of the system libraries in the image and of the playwright-php server |

A local run with local repositories and local sign-in needs no GitHub App:

```sh
export LOCAL_REPOSITORIES_HOST_DIR="$HOME/dev" APP_LOCAL_SIGN_IN=jan
make up
```

## Code conventions

- `declare(strict_types=1);` in every PHP file.
- Classes are `final` by default. Doctrine entities are not `final`.
- Value objects and DTOs are `final readonly` with constructor promotion. Closed sets are backed enums.
- Layout:

  | Directory | Content |
  |---|---|
  | `src/Domain` | Pure PHP. It imports only `App\Domain`, `Symfony\Component\Uid`, and PHP core. |
  | `src/Application` | Use cases and queries |
  | `src/Entity`, `src/Repository` | Doctrine entities and repositories |
  | `src/Doctrine` | DBAL types and embeddables |
  | `src/EventStore`, `src/Projection` | Event log writes and projections |
  | `src/Git`, `src/Source` | Git primitives and repository sources |
  | `src/Tenancy`, `src/Security` | Tenant context, SQL filter, authenticators |
  | `src/Controller` | `Api/`, `E2e/`, and the controllers outside `/api/v1` |
  | `src/Http` | The REST exception listener, the CSRF listener, the cursor codec |
  | `src/Serializer/Normalizer` | The JSON normalizers that REST and MCP share |
  | `src/Mcp` | `Tool/`, `McpToolRunner`, generated `BundledSchemas` |
  | `src/Live`, `src/Index`, `src/Anchoring`, `src/Docs` | Mercure, symbol index, re-anchoring, public documentation |
  | `src/Message`, `src/MessageHandler`, `src/Messenger` | Messenger messages, handlers, middleware |
  | `src/Command`, `src/GitHub`, `src/Webhook` | Operator commands, GitHub API, GitHub webhooks |

- Symfony, as AGENTS.md says:
  - Install packages with `composer require` and let the Flex recipe write the configuration. Write configuration by hand only where no recipe exists, with a one-line header comment that says so.
  - Use attributes: `#[Route]`, `#[IsGranted]`, `#[MapRequestPayload]`, `#[MapQueryString]`, `#[AsCommand]`, `#[AsMessageHandler]`, `#[AsEventListener]`, `#[Autowire]`, `#[When]`. A route that exists only in one environment also sets `env:` on `#[Route]`.
  - Rely on autowiring. No YAML service definition unless it is unavoidable.
- Controllers are thin: map the input, call one service, return the normalized JSON. Path IDs resolve to visible entities in argument value resolvers, so the error check order of docs/specs holds. See M11. Errors are `DomainError` subclasses. `ApiExceptionListener` maps them for REST, and `McpToolRunner` maps them for MCP.
- Tenancy:
  - Every tenant entity implements `TenantOwned`.
  - The Doctrine SQL filter is on by default and fails closed without a tenant context.
  - Only these paths turn it off, and only through `TenantFilter::suspend()`: the sign-in and token lookups, the webhook consumer, the projection rebuild, and the purge.
  - Raw SQL lives only in `final` classes named `*Sql`, and every statement on a tenant table names its tenant column: `tenant_id`, or `id` on the `tenant` table. `RawSqlTenantAuditTest` enforces this from M07 on.
  - File paths come only from typed IDs.
  - Mercure topics include the tenant ID.
- Git:
  - Git runs only through `GitRunner`, with `symfony/process` argument arrays.
  - Refs and SHAs are validated, and `--end-of-options` goes before them.
  - Secrets go only into the environment.
  - One `RepositoryLock` per repository, through `LockFactory`. Fetch, clone, and clone deletion hold it. Pin writes and reads do not.
- Dispatch Messenger messages only outside a database transaction. In `e2e`, `sync://` runs the handler during the dispatch, and `CommandExecutor` refuses to run inside a transaction.
- Time comes from `Psr\Clock\ClockInterface`.
- No `echo`, `print`, `var_dump`, `print_r`, `dump`, or `dd` in `src/`.
- Schema changes go through `make migration`, never through `doctrine:schema:update`.
- Comments are terse. Name units in identifiers, for example `$fileSizeBytes`.
- Front end:
  - Strict TypeScript.
  - React Router 8.4.0 in Data Mode: `createBrowserRouter`, route `loader`s read, route `action`s and `useFetcher` write, `router.revalidate()` in `assets/live/useLiveRevalidation.ts` refreshes after a Mercure message.
  - Local component state for the UI: `useState` for selection and drafts in progress, `useOptimistic` for new threads, drafts, and replies. No Redux, no global store, no query-cache library.
  - Primer for components. CSS modules for our layout CSS.
  - `@pierre/diffs` only inside `assets/code/CodeFile.tsx` and `assets/code/CommitDiff.tsx`.
  - API types in `assets/api/types/`, written from docs/specs and checked against `docs/specs/fixtures/`.
- PHP, TypeScript, and CSS files have typically 200 to 400 lines, 800 at most. Generated files are exempt. One class per file.

## Test conventions

Suite markers: **U** unit, **F** functional, **E** full-stack E2E, **V** Vitest, **UI** Playwright against the mocked API.

- Unit tests live in `tests/Unit`. They extend `KernelTestCase` and never boot the kernel.
- Functional tests live in `tests/Functional`. They extend `KernelTestCase` or `WebTestCase`. They run against real PostgreSQL in `app_test` and real git in temporary directories under `var/test/`. They use the `ResetDatabase` and `TemporaryDirectories` traits.
  - The `test` environment routes `async` to `in-memory://`. A test asserts the queued messages, then runs them through the `QueuedMessages` trait. The trait sends each envelope through the bus with a `ReceivedStamp`, so the middleware runs as in the worker.
- E2E tests live in `tests/E2E`. They extend `FullStackTestCase` in `tests/Support/E2E`: a `KernelTestCase` with the `PlaywrightTestCaseTrait` of `playwright-php/playwright`. PHPUnit drives Playwright's Chromium in the `php` container against `http://localhost:8081`, `FullStackTestCase::BASE_URI`. One browser serves the run, and each test gets a new browser context.
  - Fixtures are created through services in the PHPUnit process, in `app_e2e`. `E2eDatabaseExtension` selects it for the classes in `App\Tests\E2E`.
  - Navigate with `visit()` and `signInAs()`. Assert with `$this->expect()`, which polls from PHP for 5 s. `withTimeout()` changes the limit.
  - CSS and text locators pierce the open shadow roots of the code view. XPath does not.
  - Wait for conditions. Never sleep.
- Vitest tests cover pure TypeScript only. They live in `*.test.ts` files next to the code.
- UI tests live in `tests/ui/*.spec.ts`. Playwright Test runs them against the SPA that `tests/ui/vite.config.ts` builds, with no PHP, database, or git. Each spec has one `test.describe`, and each title is one short sentence about behaviour.
  - Import `test` and `expect` from `tests/ui/support/test.ts`. Its `api` fixture answers `/api/v1/**` and `POST /logout` through one `page.route` dispatcher. Register routes, or the fakes `FakeReview`, `FakeRepositories`, and `FakeTokens`, before `page.goto()`. The fakes follow the docs/specs rules: statuses, refusals, and the `awaiting` rules of architecture section 6.
  - Bodies come from `tests/ui/support/builders.ts`, which starts from `docs/specs/fixtures/` and uses the types of `assets/api/types/`. `json()` validates each body against `docs/specs/schemas/`.
  - A request without a route, a write without the double-submit CSRF proof, a body that does not validate, or an uncaught page error fails the test.
  - Assert a server-side effect through the request that the page sent: `api.bodies()` or `api.callsTo()`.
  - Live updates: wait for `[data-live="connected"]`, then call `live.push()`. It delivers the update to the fake `EventSource` on its docs/specs topic.
  - CSS and text locators pierce the open shadow roots of the code view. XPath does not. Use the gesture helpers of `tests/ui/support/codeView.ts`.
- Every test method has `#[TestDox]`: one short sentence about behaviour.
- Never mock the container. No `getContainer()->set()`, no service replacement.
  - GitHub HTTP goes through the `github.client` scoped client, mocked in `test` with responses in `tests/Fixtures/github/`. The global HTTP client is not mocked, so Mercure publishes are not caught.
  - Time goes through `ClockSensitiveTrait`.
- Test every feature the way a caller uses it: HTTP for controllers, a service call for services, JSON-RPC for MCP, a Playwright UI test for JavaScript behaviour, and an E2E test for a flow across the browser, PHP, and the database.
- Tests validate every REST response and every MCP `structuredContent` against the docs/specs schemas, through the `JsonApiAssertions` trait.
- Every new `/api/v1` route and every new MCP tool gets a cross-tenant case. The inventory tests fail otherwise.
- Do not test behaviour that the framework guarantees. Test our code and how it uses the framework.

## Definition of done

Every milestone:

1. Every acceptance test of the milestone exists, carries `#[TestDox]`, and passes.
2. `make check` is green:
   - php-cs-fixer;
   - PHPStan level 8 with no new ignores;
   - `lint:container`, `lint:yaml`, `lint:twig`;
   - `doctrine:schema:validate`;
   - the unit and functional suites;
   - `tsc` in strict mode, Vitest, and the UI suite;
   - the E2E suite.
3. Schema changes have generated migrations, applied to the dev and test databases.
4. Responses validate against docs/specs, and every new route and tool has a cross-tenant case.
5. The exact pins hold: `mcp/sdk` 0.8.1, `symfony/mcp-bundle` 0.14.1, `symfony/reprise` 1.3.0, `@symfony/reprise` 1.3.0, `@pierre/diffs` 1.5.1.
6. File sizes are within the limits. No TODO without a backlog entry.
7. The report is written, the status table is updated, and nothing is committed without Jan's permission in the session.

## Report template

The session writes it into `reports/MNN.md`:

```markdown
## Report: MNN <milestone name>

- **Status:** Done (awaiting commit), Blocked, or In progress. Whoever commits the milestone changes it to Done, with the commit date.
- **Summary:** 3 to 6 lines.
- **Files:** created, changed, and deleted, grouped by area.
- **Tests added:** the TestDox sentences, grouped by suite.
- **Verification:** each command, with its result: pass counts, PHPStan error count.
- **Deviations:** from the plan, the specs, or the architecture, each with its reason.
- **Versions:** packages installed, and newer versions found.
- **Follow-ups and open questions.**
- **Risks discovered.**
- **Next milestone:** its preconditions.
```

## Technical decisions

[Architecture section 11](../architecture.md#11-decisions) holds every decision. The milestones cite them by number, for example D35.

## Verified versions

Checked on 2026-09-29. The `@playwright/test`, `playwright-php/playwright`, and Node.js image rows on 2026-10-03. `composer.lock`, `package-lock.json`, and the images hold these versions, except `symfony/uid`: 8.1.8 is installed.

| Package | Version | Source |
|---|---|---|
| PHP | 8.5.11 | https://www.php.net/releases/index.php?json&version=8 |
| Symfony | 8.1.7. 8.2 is due 11/2026. 8.1 ends 01/2027. | https://symfony.com/releases/8.1.json |
| PostgreSQL | 18.6, Docker tag `18-alpine` | https://www.postgresql.org/versions.json |
| FrankenPHP | 1.12.7, Docker tag `dunglas/frankenphp:1.12.7-php8.5-trixie`, worker mode through Symfony Runtime's `FrankenPhpWorkerRunner` | https://github.com/php/frankenphp/releases/tag/v1.12.7 |
| dunglas/symfony-docker | Template, guide "Using Symfony Docker with an existing project" | https://github.com/dunglas/symfony-docker |
| Node.js | 24 LTS, 24.21.0 | https://nodejs.org/dist/index.json |
| Node.js in the `php` image | 24.21.0 from `node:24-trixie-slim`, npm 11.19.0 | `node --version` in the `php` container |
| `doctrine/orm`, `doctrine/doctrine-migrations-bundle`, `symfony/messenger` with the Doctrine transport | 3.7.2, 4.0.1, 8.1, installed | `composer show` |
| `symfony/process`, `symfony/uid` | 8.1.7, 8.1.5 | https://repo.packagist.org/p2/symfony/process.json |
| `mcp/sdk`, `symfony/mcp-bundle` | 0.8.1, 0.14.1, exact pins | https://repo.packagist.org/p2/mcp/sdk.json |
| `nyholm/psr7` | 1.8 | https://repo.packagist.org/p2/nyholm/psr7.json |
| `hwi/oauth-bundle` | 2.5.0 of 2026-02-19, MIT, PHP 8.3 or later, Symfony `^6.4\|^7.4\|^8.0`, uses Symfony HttpClient | https://repo.packagist.org/p2/hwi/oauth-bundle.json |
| `lcobucci/jwt` | 5.6.0, for the GitHub App JWT | https://repo.packagist.org/p2/lcobucci/jwt.json |
| `symfony/mercure-bundle` | 0.5.0 | https://repo.packagist.org/p2/symfony/mercure-bundle.json |
| `symfony/webhook`, `symfony/remote-event` | 8.1.7, 8.1.0 | https://repo.packagist.org/p2/symfony/webhook.json |
| `symfony/reprise`, `@symfony/reprise` | 1.3.0, exact pins, stable since 1.0.0 of 2026-08-26 | https://repo.packagist.org/p2/symfony/reprise.json |
| `nikic/php-parser` | 5.9.0 | https://repo.packagist.org/p2/nikic/php-parser.json |
| `twig/markdown-extra` | 3.29.0 | https://repo.packagist.org/p2/twig/markdown-extra.json |
| `league/commonmark` | 2.10.3 | https://repo.packagist.org/p2/league/commonmark.json |
| `opis/json-schema` | 2.6.0, draft 2020-12 | https://repo.packagist.org/p2/opis/json-schema.json |
| `phpunit/phpunit`, `phpstan/phpstan` | 13.3.5, 2.2.16 | https://repo.packagist.org/p2/phpunit/phpunit.json |
| `playwright-php/playwright` | 1.5.0, exact pin. Its Node server uses npm `playwright` 1.63.0. | https://repo.packagist.org/p2/playwright-php/playwright.json |
| `friendsofphp/php-cs-fixer` | 3.95.27 | https://repo.packagist.org/p2/friendsofphp/php-cs-fixer.json |
| `react`, `react-dom`, `@types/react`, `@types/react-dom` | 19.3.0 | https://www.npmjs.com/package/react |
| `react-router` | 8.4.0, Data Mode. Peers `react` and `react-dom` `>=19.2.7`. | https://www.npmjs.com/package/react-router |
| `vite`, `@vitejs/plugin-react`, `typescript` | 8.3.1, 6.1.1, 7.0.2 | https://www.npmjs.com/package/vite |
| `@pierre/diffs` | 1.5.1, exact pin, Apache-2.0 | https://www.npmjs.com/package/@pierre/diffs |
| `@primer/react`, `@primer/primitives`, `@primer/octicons-react`, `@primer/css` | 38.40.0, 11.10.0, 19.38.0, 22.3.2 | https://www.npmjs.com/package/@primer/react |
| `react-markdown`, `remark-gfm`, `vitest` | 10.1.0, 4.0.1, 5.0.2 | https://www.npmjs.com/package/react-markdown |
| `@playwright/test`, `ajv`, `ajv-formats`, `@types/node` | 1.63.0, 8.20.0, 3.0.1, 24.19.1 | https://www.npmjs.com/package/@playwright/test |

## Known gotchas

### Recipes and bundles

- **HWIOAuthBundle.** Its recipe is a contrib recipe. Version 2.5.0 requires `symfony/form`, so keep Form. It has no PKCE. Its `csrf` option defaults to `false`: set it to `true`.
- **symfony/mcp-bundle 0.14.1.** It has no Flex recipe. Only `config/bundles.php` gets `Symfony\AI\McpBundle\McpBundle`. Write `config/packages/mcp.yaml` and `config/routes/mcp.yaml` by hand. The HTTP transport needs `nyholm/psr7`. The `nyholm/psr7` and `php-http/discovery` recipes both alias the PSR-17 interfaces: keep one set.
- **MCP bundle options.** `http.allowed_hosts` defaults to localhost only, as DNS rebinding protection. The HTTP path is `servers.<name>.http.path`. The session store is one of `file`, `memory`, `cache`, or `framework`. The `cache` store needs a PSR-16 service. The default directory of the `file` store is under `%kernel.cache_dir%`, which `cache:clear` removes, so set `directory` explicitly.
- **mcp/sdk 0.8.1 behaviour:**
  - input-schema failures return JSON-RPC -32602, not `isError`;
  - any exception other than `ToolCallException` becomes -32603 and loses its message;
  - a `ToolCallException` becomes an `isError` result with the message as text and no `structuredContent`;
  - object and DTO parameters are not supported;
  - a nullable scalar parameter generates a type list with `null` first, a nullable enum or array parameter with `null` last;
  - a `list<string>` docblock produces `items: {}`, which accepts any element: array parameters need `#[Schema(items: ...)]`;
  - `outputSchema` comes only from `#[McpTool]`, so it must be a constant expression;
  - `structuredContent` must be a JSON object in the 2025-11-25 protocol era;
  - anything written to stdout corrupts the stdio transport.
- **Mercure hub URL.** Always set `hubs.default.url`. When it is unset and the container is built inside FrankenPHP, the bundle compiles `FrankenPhpHub`, which calls `mercure_publish()`. See `$builtinHub = !isset($hub['url'])` in `MercureExtension.php`. CLI processes that share `var/cache` then break.
- **Reprise recipe.** It re-creates `assets/app.js` and `assets/styles/app.css`, and edits `templates/base.html.twig`.
- **symfony/webhook.** The recipe route prefix is `/webhook`, so the GitHub endpoint is `/webhook/github`. A request parser that returns `null` makes Symfony answer 406. Return a `RemoteEvent` for every verified delivery.

### Docker and PostgreSQL

- **PostgreSQL 18 data path.** The image stores data under `/var/lib/postgresql/18/docker`. Mount the volume at `/var/lib/postgresql`, not at `/var/lib/postgresql/data`. The skeleton defaults to PostgreSQL 16.
- **Compose and .env.** Compose reads the Symfony `.env` for interpolation. Override `LOCAL_REPOSITORIES_HOST_DIR` through the shell environment.
- **Compose environment and test processes.** Real environment variables win over `.env` files. A variable in the compose `environment:` list of `php` also reaches the `test` and `e2e` processes in that container. Put per-environment values into `.env.dev`, `.env.test`, and `.env.e2e`.
- **Root in the container.** The `php` container runs as root, so permission-based tests prove nothing. Compare bytes and modification times.
- **One database role.** Do not add init scripts under `/docker-entrypoint-initdb.d/`. The aborted setup attempt learned these facts. They matter only when [docs/backlog/database-hardening.md](../backlog/database-hardening.md) is implemented:
  - an init script under `/docker-entrypoint-initdb.d/` created a non-owner runtime role;
  - the Docker `POSTGRES_USER` is a superuser, and a superuser bypasses row-level security;
  - the app then needs two connections: the owner for migrations, the runtime role for the app;
  - with the Symfony CLI, the owner URL fits `DATABASE_URL`, and the runtime URL needs its own variable.

### PHPStan level 8

- PHPStan reports `Kernel::getAllowedEnvs()` as unused. This is a false positive: `KernelTrait` calls it through `MicroKernelTrait`. Ignore it by the identifier `method.unused`.
- phpstan-doctrine needs `objectManagerLoader`: `tests/object-manager.php`.

### Mocked UI suite

- The code view turns off pointer events on its rows during a scroll and for 120 ms after it. A press right after a scroll lands on the container: `dragFromPlus()` waits until the "+" receives the pointer.
- A Playwright context denies clipboard writes. A test of a copy button grants `clipboard-read` and `clipboard-write`.
- `page.route` cannot stream a response, so `EventSource` is replaced by `addInitScript`.
- `context.route` also intercepts the imports of the `@pierre/diffs` worker. `page.route` is enough for `/api/v1`.

### Test environments and front-end builds

- **Sessions.** The `test` environment uses `mock_file` sessions. A real browser must use the `e2e` environment.
- **Build output.** The Vite dev server writes entrypoints for `public/build`. The E2E suite uses its own build in `public/build-e2e`.
- **Full-stack suite, playwright-php 1.5.0:**
  - `getByRole()` without `exact: true` matches a case-insensitive substring of the name. Pass `'exact' => true`.
  - `allTextContents()` and `allInnerTexts()` fail with "Unknown action", because the Node server has no such action. Use `evaluateAll()`.
  - `filter(['has' => $locator])` puts the selector string of `$locator` into a CSS `:has()`. A CSS locator works. A role, text, or chained locator fails with a selector parse error.
  - `expect()->toHaveText()` checks a substring. `toHaveExactText()` compares `textContent` exactly.
  - Each `expect()` poll reads the element. An element that does not exist blocks one poll for the 30 s action timeout, so the 5 s limit of `expect()` holds only for an element that exists. Wait for the element first.
  - An assertion that fails throws `AssertionException`, a `RuntimeException` whose message holds neither the actual nor the expected value. PHPUnit counts it as an error.
  - The trait saves a screenshot of a failed test to `test-failures/` in the working directory, which is git-ignored.
  - The server launches Chromium with `--no-sandbox`, `--disable-dev-shm-usage`, and `--disable-web-security`. It has no download support.
  - The playwright-php server declares `playwright ^1.62.0` without a lockfile. `make e2e` pins it to `PLAYWRIGHT_VERSION` of the image with `npm install --save-exact`, so the browser build matches the system libraries and the UI suite. Change the version only in the Dockerfile, together with `@playwright/test` in `package.json`.

### Application code

- **Process messages.** `symfony/process` exception messages include the command line. Secrets go through the environment only.
- **SQL filter coverage.** The Doctrine SQL filter does not cover DBAL queries or identity-map hits. Reset the entity manager between worker requests and between Messenger messages.
- **SQL filter parameter.** Between worker requests, DoctrineBundle's `Registry::reset()` only calls `clear()` on an open entity manager. The filter keeps its parameter. `TenantContext::reset()` must drop it. See M05.
- **Environment-specific firewalls.** Do not rely on merging a security firewall across `when@<environment>` blocks. Keep the authenticators in the base firewall and guard them in code.
- **Bearer requests and sessions.** A stateful firewall writes every authenticated token into the session in `ContextListener::onKernelResponse`. Bearer requests therefore use their own stateless firewall `api_bearer`. See M14.
- **Messenger deduplication.** Do not use `DeduplicateStamp` with the advisory-lock store. An advisory lock belongs to one database session, so the worker cannot release a lock that the web process took.
- **Live-update payloads.** They carry IDs only. Never put a `RequestSummary`, a `Review`, or other data into a Mercure update.
