# Implementation plan: Holistic Review proof of concept

This plan splits the proof of concept into 36 milestones, M00 to M35. One Claude session implements one milestone, writes a report, and stops. Jan reviews and commits.

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
2. Find the milestone to work on in the status table:
   - the first milestone with the status "In progress" continues a split. Its Notes column names the tasks that are done;
   - else the first milestone with the status "Not started";
   - a milestone with the status "Blocked" needs a decision from Jan. Stop and ask.
3. Read the milestone file completely. Read the architecture sections and the docs/specs files that it names.
4. Run `git status`. Earlier milestones can be uncommitted, because Jan commits. If you find changes that no earlier report explains, stop and ask Jan.
5. Check the baseline. If it is red, stop and report. Do not fix unrelated failures silently.
   - M00: `composer validate --no-check-publish` and `bin/console lint:container` on the host;
   - M01: the M00 verification commands on the host;
   - from M02 on: `make up`, `make db-test`, and `make check`.
6. Set the status of the milestone to "In progress".
7. Do the tasks in order. Write each acceptance test together with the code that it proves, or before it.
8. Run the verification commands of the milestone, then `make check`.
9. Set the status to "Done (awaiting commit)" or "Blocked", with the date. Write the report from the [template](#report-template) as your final message. Then stop.

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
- Never commit, push, amend, or stash. Jan reviews and commits.
- No Python. Scripts are PHP or shell.
- Use context7 to read the documentation of a library before you use its API. When context7 has no answer, read the installed source under `vendor/` or `node_modules/`.
- Before each install, check the latest version on Packagist or npm. Install the version from the [verified versions](#verified-versions) table. If a newer version exists, report it. Jan decides about upgrades. A package that the table does not list gets its latest stable version, and the report names it.
- A milestone never creates a file in `docs/backlog/`. It may add an entry to an existing backlog file and to [docs/backlog/README.md](../backlog/README.md).
- A TODO in code needs a backlog entry.

## Status

Status values: "Not started", "In progress", "Blocked", "Done (awaiting commit)", "Done". The session sets the first four. Jan sets "Done" after he commits.

| # | Milestone | Status | Date | Notes |
|---|---|---|---|---|
| M00 | [Baseline packages and quality gates](M00-baseline-packages-and-quality-gates.md) | Done | 2026-09-29 | [Report](reports/M00.md) |
| M01 | [Local Docker environment](M01-local-docker-environment.md) | Done | 2026-09-29 | [Report](reports/M01.md) |
| M02 | [Domain value objects and errors](M02-domain-value-objects-and-errors.md) | Done | 2026-09-29 | [Report](reports/M02.md) |
| M03 | [Domain events and payload codec](M03-domain-events-and-payload-codec.md) | Done | 2026-09-29 | [Report](reports/M03.md) |
| M04 | [Request lifecycle](M04-request-lifecycle.md) | In progress | 2026-09-29 | Branch `m04-request-lifecycle`. Ticked tasks in the milestone file are done. |
| M05 | [Tenancy persistence and isolation](M05-tenancy-persistence-and-isolation.md) | In progress | 2026-09-29 | Branch `m05-tenancy`. Ticked tasks in the milestone file are done. |
| M06 | [Review data schema](M06-review-data-schema.md) | Not started | | |
| M07 | [Event store and command service](M07-event-store-and-command-service.md) | Not started | | |
| M08 | [Git plumbing](M08-git-plumbing.md) | In progress | 2026-09-29 | Branch `m08-git-plumbing`. Ticked tasks in the milestone file are done. |
| M09 | [Local repository source](M09-local-repository-source.md) | Not started | | |
| M10 | [Sign-in and security](M10-sign-in-and-security.md) | Not started | | |
| M11 | [REST foundation and repositories API](M11-rest-foundation-and-repositories-api.md) | Not started | | |
| M12 | [Reviews API](M12-reviews-api.md) | Not started | | |
| M13 | [Requests API, user side](M13-requests-api-user-side.md) | Not started | | |
| M14 | [API tokens](M14-api-tokens.md) | Not started | | |
| M15 | [MCP server and read tools](M15-mcp-server-and-read-tools.md) | Not started | | |
| M16 | [MCP write tools and headless slice](M16-mcp-write-tools-and-headless-slice.md) | Not started | | Headless slice |
| M17 | [Front-end shell](M17-front-end-shell.md) | Not started | | |
| M18 | [Repositories and reviews screens](M18-repositories-and-reviews-screens.md) | Not started | | |
| M19 | [Settings: API tokens](M19-settings-api-tokens.md) | Not started | | |
| M20 | [Code view](M20-code-view.md) | Not started | | |
| M21 | [Commenting UI](M21-commenting-ui.md) | Not started | | |
| M22 | [Verify UI and vertical slice](M22-verify-ui-and-vertical-slice.md) | Not started | | Vertical slice |
| M23 | [Public docs and downloadable skill](M23-public-docs-and-downloadable-skill.md) | Not started | | |
| M24 | [Live updates via Mercure](M24-live-updates-via-mercure.md) | Not started | | |
| M25 | [Drafts, batch review, viewed files](M25-drafts-batch-review-viewed-files.md) | Not started | | |
| M26 | [Symbol index](M26-symbol-index.md) | Not started | | |
| M27 | [Symbol anchors and keyboard access](M27-symbol-anchors-and-keyboard-access.md) | Not started | | |
| M28 | [Re-anchoring core](M28-re-anchoring-core.md) | Not started | | |
| M29 | [Re-anchoring with git, locations, outdated](M29-re-anchoring-with-git-locations-outdated.md) | Not started | | |
| M30 | [Verify view diffs and outdated UI](M30-verify-view-diffs-and-outdated-ui.md) | Not started | | |
| M31 | [Data deletion and tenant purge](M31-data-deletion-and-tenant-purge.md) | Not started | | |
| M32 | [GitHub sign-in](M32-github-sign-in.md) | Not started | | |
| M33 | [GitHub App repositories](M33-github-app-repositories.md) | Not started | | |
| M34 | [GitHub webhooks](M34-github-webhooks.md) | Not started | | |
| M35 | [Isolation audit and acceptance](M35-isolation-audit-and-acceptance.md) | Not started | | |

## Order

Sessions run the milestones in number order. The "Depends on" section of each milestone file names the earlier work that it builds on.

## Environment and commands

PHP, Composer, the console, and PHPUnit run in the `php` container. `make` wraps `docker compose exec -T php`. npm and the Vite dev server run on the host. M00 and the M01 baseline use host PHP and Composer, because the container arrives in M01.

| Command | Does |
|---|---|
| `make up` | `docker compose up -d --wait`: services `php`, `worker`, `database` |
| `make down` | `docker compose down`, without `--volumes` |
| `make sh` | Shell in the `php` container |
| `make worker-restart` | `bin/console messenger:stop-workers`. Compose restarts the worker with the new code. Run it after a PHP change that the worker uses. |
| `make console c="<arguments>"` | `bin/console <arguments>` |
| `make composer c="<arguments>"` | `composer <arguments>` |
| `make migration` | `bin/console make:migration --no-interaction` |
| `make db-reset` | Dev database: drop, create, migrate. Ask Jan first. |
| `make db-test` | Database `app_test`: create it if it is missing, then migrate |
| `make test` | `bin/phpunit --testsuite unit,functional` |
| `make e2e` | `npm run build:e2e` on the host when `package.json` exists, then `bin/phpunit --testsuite e2e` |
| `make stan` | `cache:warmup --env=dev`, then `vendor/bin/phpstan analyse` at level 8 |
| `make cs` | `vendor/bin/php-cs-fixer fix --dry-run --diff` |
| `make cs-fix` | `vendor/bin/php-cs-fixer fix` |
| `make lint` | `lint:container`, `lint:yaml config`, `lint:twig templates`, `doctrine:schema:validate --env=test` |
| `make front` | `npm run typecheck && npm test` on the host when `package.json` exists |
| `make check` | `cs`, `stan`, `lint`, `test`, `front`, `e2e` |

For verbose PHPUnit output, use `--debug`. Never use `-v` or `--verbose`. Example: `docker compose exec php bin/phpunit --debug --filter StartReviewTest`.

## URLs and environment variables

| What | Where |
|---|---|
| App | `http://localhost:8000`: `dev` environment, FrankenPHP worker mode, Mercure hub at `/.well-known/mercure` |
| E2E site | `http://localhost:8081`: `e2e` environment, classic mode, reachable only inside the `php` container |
| PostgreSQL | `127.0.0.1:5432`, databases `app` for `dev` and `app_test` for `test` and `e2e` |
| Vite dev server | `npm run dev` on the host |
| MCP endpoint | `http://localhost:8000/_mcp` |
| Public documentation | `http://localhost:8000/docs`, raw skill at `http://localhost:8000/docs/skill/SKILL.md` |

| Variable | Set in | Meaning |
|---|---|---|
| `LOCAL_REPOSITORIES_HOST_DIR` | Host shell | Host directory that compose mounts read-only at `/repositories`. Default `./var/local-repositories`. |
| `APP_LOCAL_REPOSITORIES_DIR` | `.env` files | Empty in `.env`, which means that local repositories are off. `/repositories` in `.env.dev`, `var/test/local-repositories` in `.env.test` and `.env.e2e`. It is an instance feature: every tenant of the instance may connect the repositories under it. |
| `APP_LOCAL_SIGN_IN` | Host shell, passed through compose | Login of the one local user. Honoured only when `APP_ENV=dev`. |
| `APP_TENANT_DATA_DIR` | `.env` files | Root of the tenant files, for example the clones: the `<data>/tenants` directory of docs/specs. `/srv/tenants` in `.env.dev`, on the `tenant_data` volume. `var/test/tenants` in `.env.test` and `.env.e2e`. |
| `DEFAULT_URI` | `.env` | Base of the Mercure topics, from M24 on. Not the base of the links in `/api/v1/me`, which come from the request. |
| `APP_PUBLIC_HOST` | `.env` | Host that the MCP transport accepts, from M15 on |
| `LOCK_DSN` | `.env`, with the host `database` | Store of `symfony/lock`: PostgreSQL advisory locks, from M08 on |
| `MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_JWT_SECRET` | `.env` files. Also `MERCURE_URL` in the compose `worker` service, and `MERCURE_JWT_SECRET` in the compose `php` service, because Caddy reads only the container environment. | Hub URL for the publisher, hub URL for the browser, JWT secret |
| `GITHUB_APP_CLIENT_ID`, `GITHUB_APP_SLUG` | Empty in `.env`, real values in `.env.local`, fake values in `.env.test` | GitHub App identity, from M32 on. Empty means that no GitHub App is configured. |
| `GITHUB_APP_CLIENT_SECRET`, `GITHUB_APP_PRIVATE_KEY`, `GITHUB_APP_WEBHOOK_SECRET` | Secrets vault or `.env.local`, fake values in `.env.test` | GitHub App credentials, from M32 on. Read with `%env(default::<NAME>)%`, not with a default in `.env`, because a `.env` value wins over the vault. |

A local run with local repositories and local sign-in needs no GitHub App:

```sh
export LOCAL_REPOSITORIES_HOST_DIR="$HOME/dev" APP_LOCAL_SIGN_IN=jan
make up
```

## Code conventions

- `declare(strict_types=1);` in every PHP file.
- Classes are `final` by default. Doctrine entities are not `final`.
- Value objects and DTOs are `final readonly` with constructor promotion. Closed sets are backed enums.
- Do not mark a service `readonly` when it may become `lazy: true`. A lazy proxy cannot extend a `readonly` class.
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
  - Raw SQL lives only in `final` classes named `*Sql`, and every statement names `tenant_id`. `RawSqlTenantAuditTest` enforces this from M07 on.
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
  - React Router 8.4.0 in Data Mode: `createBrowserRouter`, route `loader`s read, route `action`s and `useFetcher` write, `useRevalidator` refreshes after a Mercure message.
  - Local component state for the UI: `useState` and `useReducer` for selection and drafts in progress, `useOptimistic` for thread replies. No Redux, no global store, no query-cache library.
  - Primer for components. CSS modules for our layout CSS.
  - `@pierre/diffs` only inside `assets/code/CodeFile.tsx` and `assets/code/CommitDiff.tsx`.
  - API types in `assets/api/types/`, written from docs/specs and checked against `docs/specs/fixtures/`.
- PHP, TypeScript, and CSS files have typically 200 to 400 lines, 800 at most. Generated files are exempt. One class per file.

## Test conventions

Suite markers: **U** unit, **F** functional, **E** Panther E2E, **V** Vitest.

- Unit tests live in `tests/Unit`. They extend `KernelTestCase` and never boot the kernel.
- Functional tests live in `tests/Functional`. They extend `KernelTestCase` or `WebTestCase`. They run against real PostgreSQL in `app_test` and real git in temporary directories under `var/test/`. They use the `ResetDatabase` and `TemporaryDirectories` traits.
  - The `test` environment routes `async` to `in-memory://`. A test asserts the queued messages, then runs them through the `QueuedMessages` trait. The trait sends each envelope through the bus with a `ReceivedStamp`, so the middleware runs as in the worker.
- E2E tests live in `tests/E2E`. They extend `PantherTestCase` and run against `http://localhost:8081` through `PANTHER_EXTERNAL_BASE_URI`.
  - Fixtures are created through services in the PHPUnit process, in the same `app_test` database.
  - The code view uses open shadow roots. Use the `ShadowDom` helpers.
  - Wait for conditions. Never sleep.
- Vitest tests cover pure TypeScript only. They live in `*.test.ts` files next to the code.
- Every test method has `#[TestDox]`: one short sentence about behaviour.
- Never mock the container. No `getContainer()->set()`, no service replacement.
  - GitHub HTTP goes through `framework.http_client.mock_response_factory` with recorded responses in `tests/Fixtures/github/`.
  - Time goes through `ClockSensitiveTrait`.
- Test every feature the way a caller uses it: HTTP for controllers, a service call for services, JSON-RPC for MCP, a browser for JavaScript behaviour.
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
   - `tsc` in strict mode and Vitest, from M17 on;
   - the E2E suite.
3. Schema changes have generated migrations, applied to the dev and test databases.
4. Responses validate against docs/specs, and every new route and tool has a cross-tenant case.
5. The exact pins hold: `mcp/sdk` 0.8.1, `symfony/mcp-bundle` 0.14.1, `symfony/reprise` 1.3.0, `@symfony/reprise` 1.3.0, `@pierre/diffs` 1.5.1.
6. File sizes are within the limits. No TODO without a backlog entry.
7. The report is written, the status table is updated, and nothing is committed.

## Report template

The final message of the session:

```markdown
## Report: MNN <milestone name>

- **Status:** Done (awaiting commit), Blocked, or In progress.
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

Checked on 2026-09-29.

| Package | Version | Source |
|---|---|---|
| PHP | 8.5.11 | https://www.php.net/releases/index.php?json&version=8 |
| Symfony | 8.1.7. 8.2 is due 11/2026. 8.1 ends 01/2027. | https://symfony.com/releases/8.1.json |
| PostgreSQL | 18.6, Docker tag `18-alpine` | https://www.postgresql.org/versions.json |
| FrankenPHP | 1.12.7, Docker tag `dunglas/frankenphp:1.12.7-php8.5-trixie`, worker mode through Symfony Runtime's `FrankenPhpWorkerRunner` | https://github.com/php/frankenphp/releases/tag/v1.12.7 |
| dunglas/symfony-docker | Template, guide "Using Symfony Docker with an existing project" | https://github.com/dunglas/symfony-docker |
| Node.js | 24 LTS, 24.21.0 | https://nodejs.org/dist/index.json |
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
| `symfony/panther`, `php-webdriver/webdriver`, `dbrekelmans/bdi` | 2.4.0, 1.16.0, 1.4.1 | https://repo.packagist.org/p2/symfony/panther.json |
| `friendsofphp/php-cs-fixer` | 3.95.27 | https://repo.packagist.org/p2/friendsofphp/php-cs-fixer.json |
| `react`, `react-dom`, `@types/react`, `@types/react-dom` | 19.3.0 | https://www.npmjs.com/package/react |
| `react-router` | 8.4.0, Data Mode. Peers `react` and `react-dom` `>=19.2.7`. | https://www.npmjs.com/package/react-router |
| `vite`, `@vitejs/plugin-react`, `typescript` | 8.3.1, 6.1.1, 7.0.2 | https://www.npmjs.com/package/vite |
| `@pierre/diffs` | 1.5.1, exact pin, Apache-2.0 | https://www.npmjs.com/package/@pierre/diffs |
| `@primer/react`, `@primer/primitives`, `@primer/octicons-react`, `@primer/css` | 38.40.0, 11.10.0, 19.38.0, 22.3.2 | https://www.npmjs.com/package/@primer/react |
| `react-markdown`, `remark-gfm`, `vitest` | 10.1.0, 4.0.1, 5.0.2 | https://www.npmjs.com/package/react-markdown |

## Known gotchas

### Package removal, M00

- **Messenger routing.** `config/packages/messenger.yaml` routes `SendEmailMessage`, `ChatMessage`, and `SmsMessage`. Remove those lines before `composer remove symfony/mailer symfony/notifier`, or the post-remove `cache:clear` fails.
- **AssetMapper leftovers.** Removing `symfony/asset-mapper` leaves the untracked `assets/vendor/`. Delete it, together with the importmap leftovers.

### Recipes and bundles

- **HWIOAuthBundle.** Its recipe is a contrib recipe, and `allow-contrib` is `false`. Switch it on first. Version 2.5.0 requires `symfony/form`, so keep Form. It has no PKCE. Its `csrf` option defaults to `false`: set it to `true`.
- **symfony/mcp-bundle 0.14.1.** It has no Flex recipe. Only `config/bundles.php` gets `Symfony\AI\McpBundle\McpBundle`. Write `config/packages/mcp.yaml` and `config/routes/mcp.yaml` by hand. The HTTP transport needs `nyholm/psr7`. The `nyholm/psr7` and `php-http/discovery` recipes both alias the PSR-17 interfaces: keep one set.
- **MCP bundle options.** `http.allowed_hosts` defaults to localhost only, as DNS rebinding protection. The HTTP path is `servers.<name>.http.path`. The session store is one of `file`, `memory`, `cache`, or `framework`. The `cache` store needs a PSR-16 service. The default directory of the `file` store is under `%kernel.cache_dir%`, which `cache:clear` removes, so set `directory` explicitly.
- **mcp/sdk 0.8.1 behaviour:**
  - input-schema failures return JSON-RPC -32602, not `isError`;
  - any exception other than `ToolCallException` becomes -32603 and loses its message;
  - a `ToolCallException` becomes an `isError` result with the message as text and no `structuredContent`;
  - object and DTO parameters are not supported;
  - a nullable parameter generates a type list with `null` first;
  - a `list<string>` docblock produces no `items`: array parameters need `#[Schema(items: ...)]`;
  - `outputSchema` comes only from `#[McpTool]`, so it must be a constant expression;
  - `structuredContent` must be a JSON object in the 2025-11-25 protocol era;
  - anything written to stdout corrupts the stdio transport.
- **Mercure recipe.** It adds a `dunglas/mercure` service to compose. It is not needed, because FrankenPHP has the hub built in. Remove it if it appears.
- **Mercure hub URL.** Always set `hubs.default.url`. When it is unset and the container is built inside FrankenPHP, the bundle compiles `FrankenPhpHub`, which calls `mercure_publish()`. See `$builtinHub = !isset($hub['url'])` in `MercureExtension.php`. CLI processes that share `var/cache` then break.
- **Reprise recipe.** It re-creates `assets/app.js` and edits `templates/base.html.twig`.
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
- `tests/bootstrap.php` has a `method_exists()` check that is always true. Fix the code. Do not ignore the error.
- phpstan-doctrine needs `objectManagerLoader`: `tests/object-manager.php`.

### Test environments and front-end builds

- **Sessions.** The `test` environment uses `mock_file` sessions. A real browser must use the `e2e` environment.
- **Build output.** The Vite dev server writes entrypoints for `public/build`. The E2E suite uses its own build in `public/build-e2e`.
- **Panther in Docker:**
  - set `PANTHER_NO_SANDBOX=1`, `PANTHER_CHROME_ARGUMENTS=--disable-dev-shm-usage`, and `PANTHER_CHROME_BINARY=/usr/bin/chromium`;
  - the code view renders in open shadow roots: use `getShadowRoot()`;
  - Primer overlays fade in over 0.2 s: wait for visibility.

### Application code

- **Process messages.** `symfony/process` exception messages include the command line. Secrets go through the environment only.
- **SQL filter coverage.** The Doctrine SQL filter does not cover DBAL queries or identity-map hits. Reset the entity manager between worker requests and between Messenger messages.
- **SQL filter parameter.** Between worker requests, DoctrineBundle's `Registry::reset()` only calls `clear()` on an open entity manager. The filter keeps its parameter. `TenantContext::reset()` must drop it. See M05.
- **Environment-specific firewalls.** Do not rely on merging a security firewall across `when@<environment>` blocks. Keep the authenticators in the base firewall and guard them in code.
- **Bearer requests and sessions.** A stateful firewall writes every authenticated token into the session in `ContextListener::onKernelResponse`. Bearer requests therefore use their own stateless firewall `api_bearer`. See M14.
- **Messenger deduplication.** Do not use `DeduplicateStamp` with the advisory-lock store. An advisory lock belongs to one database session, so the worker cannot release a lock that the web process took.
- **Live-update payloads.** They carry IDs only. Never put a `RequestSummary`, a `Review`, or other data into a Mercure update.
