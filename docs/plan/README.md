# Implementation plan: Slopmower proof of concept

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
| `docs/specs/schemas/<name>.schema.json` | JSON Schemas, draft 2020-12, `$id` base `https://slopmower.invalid/schemas/v1/` |
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

The [simplification plan](simplification.md), S00 to S14, is complete. Its status table holds the baseline and the metrics after each step. New features run in the [feature track](features.md). Hard rule: every feature request is documented there before any code, see [AGENTS.md](../../AGENTS.md#hard-rule-every-feature-request-is-documented).

## Order

Jan assigns the milestones. Without an assignment, sessions run them in number order. A milestone can start when the milestones in its "Depends on" section are done. The "Depends on" section of each milestone file names the earlier work that it builds on. The orchestrator started independent milestones in parallel worktrees, so the commit order on `main` differs from the number order.

## Environment and commands

PHP, Composer, the console, and PHPUnit run in the `php` container. `make` wraps `docker compose exec -T php`. npm and the Vite dev server run on the host. M00 and the M01 baseline use host PHP and Composer, because the container arrives in M01.

| Command | Does |
|---|---|
| `make help` | Lists the targets. The default target. |
| `make up` | `docker compose up -d --wait --build`: services `php`, `worker`, `symbols`, `database`. At each start, the entrypoint of `php` runs `composer install` when `composer.lock` does not match `vendor/composer.lock.md5`, migrates `app`, and creates and migrates `app_test` and `app_e2e` (D74). |
| `make down` | `docker compose down`, without `--volumes` |
| `make sh` | Shell in the `php` container |
| `make worker-restart` | `bin/console messenger:stop-workers`. Compose restarts the worker with the new code. Run it after a PHP change that the worker uses. |
| `make console c="<arguments>"` | `bin/console <arguments>` |
| `make composer c="<arguments>"` | `composer <arguments>` |
| `make mate c="<arguments>"` | `vendor/bin/mate <arguments>`: Symfony AI Mate tools, for example `tools:list` or `tools:call monolog-tail --level=ERROR`. The project tools `review-list` and `review-events` in `mate/src` read the reviews and the event log of the dev database, as `mate/INSTRUCTIONS.md` describes. |
| `make migration` | `bin/console make:migration --no-interaction` |
| `make db-reset` | Dev database: drop, create, migrate. Ask Jan first. Run `make fixtures` after it. |
| `make fixtures` | `bin/console foundry:load-fixtures dev --append --no-transaction`: the local user of `APP_LOCAL_SIGN_IN`, the agent token `APP_DEV_API_TOKEN` of `.env.dev`, and the local repository `APP_DEV_REPOSITORY`. A second load adds no row. |
| `make db-test` | Databases `app_test` and `app_e2e`: create each one if it is missing, then migrate it |
| `make test` | `bin/phpunit --testsuite unit,functional`. Runs `npm run build:e2e` first when `package.json` exists and `public/build-e2e/entrypoints.json` is missing. |
| `make e2e` | `npm run build:e2e` on the host when `package.json` exists, then in the `php` container: `npm install --save-exact playwright@$PLAYWRIGHT_VERSION` for the playwright-php server, `vendor/bin/playwright-install chromium` (returns at once when the browser is present), and `bin/phpunit --testsuite e2e` |
| `make stan` | `cache:warmup --env=test`, then `vendor/bin/phpstan analyse` at level 8 on the test container, so the dev worker keeps its cache |
| `make cs` | `vendor/bin/php-cs-fixer fix --dry-run --diff` |
| `make cs-fix` | `vendor/bin/php-cs-fixer fix` |
| `make lint` | `lint:container`, `lint:yaml config`, `lint:twig templates`, `doctrine:schema:validate --env=test` |
| `make front` | On the host when `package.json` exists: `npm ci --prefix symbols --ignore-scripts` when `symbols/package-lock.json` is newer than the installed packages, then `npm run typecheck && npm test` |
| `make ui` | On the host when `package.json` exists: `npx playwright install chromium` (returns at once when the browser is present), then `npm run test:ui`: Playwright against the mocked API, on `UI_PORT` |
| `make journeys` | On the host when `package.json` exists: `npx playwright install chromium`, then `npm run test:journeys`: the journeys of `tests/journeys/` against the mocked API, on `JOURNEY_PORT`. They write the screenshots to `var/screenshots/`, and the target prints the review URL `/_dev/screenshots`. Not a gate: `make check` and `qa.yml` do not run it. |
| `make prod-check` | `bin/prod-check`: builds the image `frankenphp_prod`, runs `bin/console lint:container` in it, starts it with `APP_PUBLIC_HOST=prod-check.example`, and requests `/login`, which must give 200. Removes its container and its image. The QA workflow runs it. `make check` does not. |
| `make act` | `bin/act-run`: copies the working tree without ignored files into `var/act/workspace/`, so the run installs `vendor/` and `node_modules/` as on GitHub, and runs `.github/workflows/qa.yml` with act. The job runs the compose project `slopmower-act` on the ports of `.github/act.env`. `make act` removes that project with its volumes before and after the run. Artifacts go to `var/act/artifacts/`. The action and cache servers of act keep their default directories under `~/.cache/`. One act run per Docker daemon at a time. |
| `make check` | `cs`, `stan`, `lint`, `test`, `front`, `ui`, `e2e`, then `make act` when no green `make act` has seen the current content of `ACT_INPUTS`. A green `make act` records the hash of that content in `.git/act-verified/`, shared by every worktree. With `CI` or `ACT` set, `make check` never runs act. |

For verbose PHPUnit output, use `--debug`. Never use `-v` or `--verbose`. Example: `docker compose exec php bin/phpunit --debug --filter StartReviewTest`.

## URLs and environment variables

The host ports 8000 and 5432 are the defaults of `HTTP_PORT` and `DATABASE_PUBLISHED_PORT`.

| What | Where |
|---|---|
| App | `http://localhost:8000`: `dev` environment, FrankenPHP worker mode, Mercure hub at `/.well-known/mercure` |
| E2E site | `http://localhost:8081`: `e2e` environment, classic mode, reachable only inside the `php` container |
| PostgreSQL | `127.0.0.1:5432`, databases `app` for `dev`, `app_test` for `test`, and `app_e2e` for `e2e` |
| Vite dev server | `npm run dev` on the host. The `dev` shell pages need it running, or a build from `npm run build`. |
| MCP endpoint | `http://localhost:8000/_mcp` |
| Public documentation | `http://localhost:8000/docs`, raw skill at `http://localhost:8000/docs/skill/SKILL.md` |
| Screenshot review | `http://localhost:8000/_dev/screenshots`: `dev` only, the captures of `make journeys` |

| Variable | Set in | Meaning |
|---|---|---|
| `HTTP_PORT`, `DATABASE_PUBLISHED_PORT` | `.env.make.local` (git-ignored), read by the `Makefile` | Host ports of `php` and `database`. Default 8000 and 5432. A second checkout, for example a git worktree, sets its own ports. Each line needs `export`, for example `export HTTP_PORT=8101`, or make does not pass it to compose. |
| `UI_PORT` | `.env.make.local`, exported by the `Makefile`, read by `playwright.config.ts` | Port of the `vite preview` server of the UI suite. Default 4173. A second checkout sets its own, for example `export UI_PORT=4101`. |
| `JOURNEY_PORT` | `.env.make.local`, exported by the `Makefile` with the default 4174, read by `playwright.journeys.config.ts` | Port of the `vite preview` server of the journeys, so that `make ui` and `make journeys` can run at the same time. A second checkout sets its own, for example `export JOURNEY_PORT=4111`. |
| `ACT_HTTP_PORT`, `ACT_DATABASE_PUBLISHED_PORT`, `ACT_UI_PORT` | `.env.make.local`, read by `bin/act-run` | Override `HTTP_PORT`, `DATABASE_PUBLISHED_PORT`, and `UI_PORT` of the `make act` job. Defaults 8290, 5490, and 4290 in `.github/act.env`, which act reads in place of `.env`. |
| `COMPOSER_CACHE_DIR` | `.github/workflows/qa.yml`, passed through by the compose `php` service | `/app/var/composer-cache` in the workflow, so that its cache step saves what the entrypoint downloads. Unset elsewhere. |
| `MIGRATE_DB`, `MIGRATE_DB_AND_CONTINUE` | `compose.yaml`, the `php` service: `latest` and `1`. The `worker` service sets neither. | The entrypoint migrates to the version `MIGRATE_DB`, in dev also `app_test` and `app_e2e`, then exits 0 unless `MIGRATE_DB_AND_CONTINUE` is set (D74). [Dockerised deployment](../deployment/dockerised.md#release) uses it as the release step. |
| `LOCAL_REPOSITORIES_HOST_DIR` | Host shell | Host directory that compose mounts read-only at `/repositories`. Default `./var/local-repositories`. |
| `APP_LOCAL_REPOSITORIES_DIR` | `.env` files | Empty in `.env`, which means that local repositories are off. `/repositories` in `.env.dev`, `var/test/local-repositories` in `.env.test` and `.env.e2e`. It is an instance feature: every tenant of the instance may connect the repositories under it. |
| `APP_LOCAL_SIGN_IN` | Host shell, passed through compose | Login of the one local user. Honoured only when `APP_ENV=dev`. |
| `APP_TENANT_DATA_DIR` | `.env` files | Root of the tenant files, for example the clones: the `<data>/tenants` directory of docs/specs. `/srv/tenants` in `.env.dev`, on the `tenant_data` volume. `var/test/tenants` in `.env.test` and `.env.e2e`. |
| `DEFAULT_URI` | `.env` | Base of the Mercure topics, from M24 on. Not the base of the links in `/api/v1/me`, which come from the request. |
| `APP_PUBLIC_HOST` | `.env` | Host that the MCP transport accepts, from M15 on |
| `DATABASE_HOST`, `DATABASE_PORT`, `DATABASE_NAME`, `DATABASE_USER`, `DATABASE_PASSWORD`, `DATABASE_SERVER_VERSION`, `DATABASE_SSLMODE` | `.env`, with the values of the compose database. The deployment sets them. | Parameters of the Doctrine connection `default` (D63), with no URL encoding. `DATABASE_PORT` is the port inside the compose network, not the host port. Empty `DATABASE_SSLMODE`: DBAL omits `sslmode`. `dbname_suffix` adds `_test` or `_e2e` to `DATABASE_NAME`. |
| `AWS_REGION`, `RDS_IAM_USERNAME`, `RDS_SECRET_ARN` | Empty in `.env`. The deployment sets them. | Database credentials through `tacticmedia/rds-auth-bundle` (D58). `AWS_REGION` with `RDS_IAM_USERNAME`: IAM token authentication. `AWS_REGION` with `RDS_SECRET_ARN`: the master password from Secrets Manager after a rotation. Empty: `DATABASE_USER` and `DATABASE_PASSWORD` pass through. |
| `APP_CACHE_DIR` | Compose `worker` service | `var/cache/worker`: the worker's own cache directory. Cache pools, and so the `messenger:stop-workers` signal, stay shared in `APP_SHARE_DIR`. |
| `MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_JWT_SECRET` | `.env` files. Also `MERCURE_URL` in the compose `worker` service, because the hub runs in the `php` container, and `MERCURE_JWT_SECRET` in the compose `php` service, because Caddy reads only the container environment. | Hub URL for the publisher, hub URL for the browser, JWT secret |
| `GITHUB_APP_CLIENT_ID`, `GITHUB_APP_SLUG` | Empty in `.env`, real values in `.env.local`, fake values in `.env.test` and `.env.e2e` | GitHub App identity, from M32 on. Empty means that no GitHub App is configured. |
| `SYMBOLS_URL` | `.env`, default `http://symbols:3000` | Base URL of the `symbols` service for the scoped client `symbols.client` (D56). Every deployment runs the service and sets it. [Deployment](../deployment/README.md#symbols-service) covers a run outside Compose. A test points it at a closed port, `http://symbols:9`, to stand for a service that is down. |
| `SYMBOLS_HOST`, `SYMBOLS_PORT` | Environment of the `symbols` service. `SYMBOLS_HOST=0.0.0.0` in the `symbols` stage of the `Dockerfile`. | Listen address and TCP port of the service (F37), by default `127.0.0.1` and `3000`. An empty value means the default. |
| `GITHUB_GIT_BASE_URL` | `.env`, default `https://github.com` | Base URL for GitHub clone and fetch, from M33 on. Tests point it at a local bare repository. The token extraheader stays bound to `https://github.com`, so another base URL never receives the token. |
| `GITHUB_APP_CLIENT_SECRET`, `GITHUB_APP_PRIVATE_KEY`, `GITHUB_APP_WEBHOOK_SECRET` | Secrets vault or `.env.local`, fake values in `.env.test` | GitHub App credentials: the client secret from M32 on, the private key from M33 on, the webhook secret from M34 on. Read with `%env(default::<NAME>)%`, not with a default in `.env`, because a non-empty `.env` value wins over the vault. |
| `APP_PLAN_FREE_*`, `APP_RATE_LIMIT_*` | `.env`, listed in [deployment](../deployment/README.md#usage-and-rate-limits) | The usage limits of the free plan and the rate limits of the instance (D32). A test sets a low limit through its variable with the `EnvironmentVariables` trait. |
| `APP_PLAN_FREE_MARKETPLACE_PLAN_ID`, `APP_PAID_PLANS`, `GITHUB_MARKETPLACE_URL` | Empty in `.env`, listed in [deployment](../deployment/README.md#usage-and-rate-limits). `.env.test` sets the plans Team 1111 and Pro 1313 of the Marketplace fixtures. | The GitHub Marketplace plans and listing (D65) |
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
  | `src/Domain` | Pure PHP, so unit tests run it without the kernel (D44). It imports only `App\Domain`, `Symfony\Component\Uid`, and PHP core. |
  | `src/Application` | Use cases and queries |
  | `src/Entity`, `src/Repository` | Doctrine entities and repositories |
  | `src/Doctrine` | DBAL types, DQL functions, and embeddables |
  | `src/EventStore`, `src/Projection` | Event log writes and projections |
  | `src/Git`, `src/Source` | Git primitives and repository sources |
  | `src/Tenancy`, `src/Security` | Tenant context, SQL filters, authenticators |
  | `src/Controller` | `Api/`, `E2e/`, and the controllers outside `/api/v1` |
  | `src/Http` | The `/api/v1` listeners, the cursor codec, the `QueryInput` marker, the Sentry `before_send` callback |
  | `src/Serializer/Normalizer` | The value normalizers, and one normalizer per schema whose response class cannot be schema-shaped. REST and MCP share them. |
  | `src/Mcp` | `McpTools` with every tool, the session decorators, generated `BundledSchemas` |
  | `src/Live`, `src/Index`, `src/Anchoring`, `src/Docs` | Mercure, symbol index, re-anchoring, public documentation |
  | `src/Message`, `src/MessageHandler`, `src/Messenger`, `src/Schedule.php` | Messenger messages, every Messenger handler, middleware, the SQL of the queue `failed`, the schedule of the recurring jobs |
  | `src/Command`, `src/GitHub`, `src/Webhook` | Operator commands, GitHub API, GitHub webhooks |

- Symfony, as AGENTS.md says:
  - Install packages with `composer require` and let the Flex recipe write the configuration. Write configuration by hand only where no recipe exists, with a one-line header comment that says so.
  - Use attributes: `#[Route]`, `#[IsGranted]`, `#[MapRequestPayload]`, `#[MapQueryString]`, `#[AsCommand]`, `#[AsMessageHandler]`, `#[AsEventListener]`, `#[Autowire]`, `#[When]`. A route that exists only in one environment also sets `env:` on `#[Route]`.
  - Rely on autowiring. No YAML service definition unless it is unavoidable.
- Paginated lists use `Http\Cursor`: `decode()` checks the sort key types, and `next()` makes `nextCursor`. The updates feed V10 takes the event `seq` as its cursor.
- A controller maps the input and returns the normalized JSON. Logic that another caller shares is in a service. Logic with no other consumer stays in the controller, in the action or a private method. Path IDs resolve to visible entities in argument value resolvers, so the error check order of docs/specs holds. See M11. Errors are `DomainError` subclasses. `ApiExceptionListener` maps them for REST, and `McpTools::run()` maps them for MCP.
- Reads:
  - A read that several callers share, REST and MCP (D37) or a controller and a use case, is one method of an `Application\Query` service. A method that builds the same view as such a read stays next to it, for example `ReviewQuery::get()`.
  - A read with one caller stays in its controller, for example Q9 in `CommitDiffController`.
  - The response class of an endpoint is in `Application\Query`, also for a read that stays in its controller and for a write, for example `IssuedApiToken` of T2.
- Responses go through the Serializer that REST and MCP share:
  - A response class is schema-shaped: its public properties are the fields of its schema, in schema order, and `ObjectNormalizer` writes it. An array literal in an action follows the same rule.
  - A class cannot be schema-shaped when it wraps an entity, a recorded event, or an `App\Git` object, when it merges another schema, or when it computes a field. Such a class has one normalizer named after its schema, and that normalizer also maps its nested parts. The normalizers are in `src/Serializer/Normalizer`.
  - Values go through the value normalizers, and an anchor goes through `AnchorCodec::encode()`, the stored payload format. The value normalizers are `ValueObjectNormalizer`, `ActorNormalizer`, `UtcTimeNormalizer`, and the framework enum normalizer. Pass the value object, not its string.
- Tenancy:
  - Every tenant entity implements `TenantOwned`.
  - The Doctrine SQL filter is on by default and fails closed without a tenant context.
  - Only these paths turn it off, and only through `TenantFilter::suspend()`: the sign-in and token lookups, the webhook consumer, the projection rebuild, and the purge.
  - Raw SQL lives only in `final` classes named `*Sql`, and every statement on a tenant table names its tenant column: `tenant_id`, or `id` on the `tenant` table. `RawSqlTenantAuditTest` enforces this from M07 on.
  - An entity stores a typed ID in a column of the ID class type, `#[ORM\Column(type: TenantId::class)]`, and a DQL parameter binds it with that type: `setParameter('id', $id, RequestId::class)`. Raw SQL, the SQL filter parameters, and DQL list parameters take `Identifier::toRfc4122()`, because an ID column is a native `uuid` on PostgreSQL.
  - File paths come only from typed IDs.
  - Mercure topics include the tenant ID.
- Git:
  - Git runs only through `GitRunner`, with `symfony/process` argument arrays.
  - Refs and SHAs are validated, and `--end-of-options` goes before them.
  - Secrets go only into the environment.
  - One `RepositoryLock` per repository, through `LockFactory`. Fetch, clone, and clone deletion wait for it with `RepositoryLock::acquire()`. Pin writes and reads do not take it.
- Dispatch Messenger messages only outside a database transaction. In `e2e`, `sync://` runs the handler during the dispatch, and `CommandExecutor` refuses to run inside a transaction.
- Time comes from `Psr\Clock\ClockInterface`.
- No `echo`, `print`, `var_dump`, `print_r`, `dump`, or `dd` in `src/`.
- Schema changes go through `make migration`, never through `doctrine:schema:update`.
- Comments are terse. Name units in identifiers, for example `$fileSizeBytes`.
- Front end:
  - Strict TypeScript.
  - React Router 8.4.0 in Data Mode: `createBrowserRouter`, route `loader`s read, route `action`s and `useFetcher` write, `router.revalidate()` in `assets/live/useLiveRevalidation.ts` refreshes after a Mercure message.
  - Local component state for the UI: `useState` for selection and drafts in progress, `useOptimistic` for new threads, drafts, and replies. No Redux, no global store, no query-cache library.
  - Per-browser UI preferences (the collapsed file tree, line wrapping) go through `assets/layout/usePreference.ts`, which reads and writes `localStorage` inside `try`/`catch`. The only other browser storage is the width of the tree pane, which Primer `PageLayout.Pane` keeps under its `widthStorageKey`, and the dismissal of the cookie notice in `templates/base.html.twig`. `docs/user/privacy.md` lists every cookie and storage key with its purpose and lifetime.
  - A write form, `fetcher.Form`, takes `onSubmit` from `assets/layout/useSubmitOnce.ts`. Primer's `loading` and the fetcher state, which commits in a transition, do not stop a quick second click or Enter.
  - Primer for components. CSS modules for our layout CSS.
  - `@pierre/diffs` only inside `assets/code/CodeFile.tsx`, `assets/code/CommitDiff.tsx`, and their worker pool options in `assets/code/workerPool.ts`.
  - API types in `assets/api/types/`, written from docs/specs and checked against `docs/specs/fixtures/`.
- A PHP, TypeScript, or CSS file has at most 800 lines. `FileSizeLimitTest` enforces it. Generated files are exempt. One class per file.

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
- Journeys live in `tests/journeys/*.spec.ts`, one `test.describe` per area. They are no gate and prove no behaviour. Import `test` and `expect` from `tests/journeys/support/test.ts`, which adds `captureScreen(label, content)` to the fixtures of the UI suite, and build the data with the fakes and builders of `tests/ui/support/`. One label per screen state. A feature that changes a screen updates its journey.
- Every test method has `#[TestDox]`: one short sentence about behaviour.
- Never mock the container. No `getContainer()->set()`, no service replacement.
  - GitHub HTTP goes through the `github.client` scoped client, mocked in `test` with responses in `tests/Fixtures/github/`. The global HTTP client is not mocked, so Mercure publishes are not caught.
  - `symbols.client` is not mocked: functional tests call the `symbols` service of the compose stack.
  - Time goes through `ClockSensitiveTrait`. It also drives the rate limiters, see "Known gotchas".
- Test every feature the way a caller uses it: HTTP for controllers, a service call for services, JSON-RPC for MCP, a Playwright UI test for JavaScript behaviour, and an E2E test for a flow across the browser, PHP, and the database.
- Tests validate every REST response and every MCP `structuredContent` against the docs/specs schemas, through the `JsonApiAssertions` trait.
- Every new `/api/v1` route and every new MCP tool gets a cross-tenant case. The inventory tests fail otherwise.
- Do not test behaviour that the framework guarantees. Test our code and how it uses the framework.
- CI runs `.github/workflows/qa.yml` (D62). Each gate is a `make` target in `check` and one step `run: make <target>` in the workflow, with the `if:` of the other gate steps, never a second command. `QaWorkflowTest` checks both. A new file that defines a gate goes into `ACT_INPUTS` of the `Makefile`, so that `make check` runs `make act` after it changes.

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
   - the E2E suite;
   - `make act`, when a file of `ACT_INPUTS` changed since the last green run.
3. Schema changes have generated migrations, applied to the dev and test databases.
4. Responses validate against docs/specs, and every new route and tool has a cross-tenant case.
5. The exact pins hold: `mcp/sdk` 0.8.1, `symfony/mcp-bundle` 0.14.1, `symfony/reprise` 1.3.0, `@symfony/reprise` 1.3.0, `@pierre/diffs` 1.5.2.
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

Checked on 2026-09-29. The `@playwright/test`, `playwright-php/playwright`, and Node.js image rows on 2026-10-03; the Symfony, Doctrine ORM, PHPUnit, PHPStan, Symfony AI Mate, `@primer/behaviors`, and `@pierre/diffs` rows on 2026-10-07; the tree-sitter and `symbols` image rows on 2026-10-08; the `symfony/scheduler` row on 2026-10-13; the `sentry/*` row on 2026-10-15; the `tacticmedia/qa-bundle` and `@tacticmedia/qa-capture` rows on 2026-10-16. `composer.lock`, `package-lock.json`, `symbols/package-lock.json`, and the images hold these versions.

| Package | Version | Source |
|---|---|---|
| PHP | 8.5.11 | https://www.php.net/releases/index.php?json&version=8 |
| Symfony | 8.1.8. 8.2 is due 11/2026. 8.1 ends 01/2027. | https://symfony.com/releases/8.1.json |
| PostgreSQL | 18.6, Docker tag `18-alpine` | https://www.postgresql.org/versions.json |
| FrankenPHP | 1.12.7, Docker tag `dunglas/frankenphp:1.12.7-php8.5-trixie`, worker mode through Symfony Runtime's `FrankenPhpWorkerRunner` | https://github.com/php/frankenphp/releases/tag/v1.12.7 |
| dunglas/symfony-docker | Template, guide "Using Symfony Docker with an existing project" | https://github.com/dunglas/symfony-docker |
| Node.js | 24 LTS, 24.21.0 | https://nodejs.org/dist/index.json |
| Node.js in the `php` image | 24.21.0 from `node:24-trixie-slim`, npm 11.19.0 | `node --version` in the `php` container |
| `doctrine/orm`, `doctrine/doctrine-migrations-bundle`, `symfony/messenger` with the Doctrine transport | 3.7.4, 4.0.1, 8.1, installed | `composer show` |
| `symfony/process`, `symfony/uid` | 8.1.7, 8.1.8 | https://repo.packagist.org/p2/symfony/process.json |
| `mcp/sdk`, `symfony/mcp-bundle` | 0.8.1, 0.14.1, exact pins | https://repo.packagist.org/p2/mcp/sdk.json |
| `nyholm/psr7` | 1.8 | https://repo.packagist.org/p2/nyholm/psr7.json |
| `hwi/oauth-bundle` | 2.5.0 of 2026-02-19, MIT, PHP 8.3 or later, Symfony `^6.4\|^7.4\|^8.0`, uses Symfony HttpClient | https://repo.packagist.org/p2/hwi/oauth-bundle.json |
| `lcobucci/jwt` | 5.6.0, for the GitHub App JWT | https://repo.packagist.org/p2/lcobucci/jwt.json |
| `symfony/mercure-bundle` | 0.5.0 | https://repo.packagist.org/p2/symfony/mercure-bundle.json |
| `symfony/webhook`, `symfony/remote-event` | 8.1.7, 8.1.0 | https://repo.packagist.org/p2/symfony/webhook.json |
| `symfony/scheduler` | 8.1.8 | https://repo.packagist.org/p2/symfony/scheduler.json |
| `sentry/sentry`, `sentry/sentry-symfony` | 4.34.0, 5.14.0 | https://repo.packagist.org/p2/sentry/sentry-symfony.json |
| `symfony/reprise`, `@symfony/reprise` | 1.3.0, exact pins, stable since 1.0.0 of 2026-08-26 | https://repo.packagist.org/p2/symfony/reprise.json |
| `nikic/php-parser` | 5.9.0 | https://repo.packagist.org/p2/nikic/php-parser.json |
| `twig/markdown-extra` | 3.29.0 | https://repo.packagist.org/p2/twig/markdown-extra.json |
| `league/commonmark` | 2.10.3 | https://repo.packagist.org/p2/league/commonmark.json |
| `opis/json-schema` | 2.6.0, draft 2020-12 | https://repo.packagist.org/p2/opis/json-schema.json |
| `phpunit/phpunit`, `phpstan/phpstan` | 13.4.1, 2.3.0 | https://repo.packagist.org/p2/phpunit/phpunit.json |
| `symfony/ai-mate`, `symfony/ai-monolog-mate-extension`, `symfony/ai-symfony-mate-extension` | 0.14.0, dev only | https://repo.packagist.org/p2/symfony/ai-mate.json |
| `playwright-php/playwright` | 1.5.0, exact pin. Its Node server uses npm `playwright` 1.63.0. | https://repo.packagist.org/p2/playwright-php/playwright.json |
| `tacticmedia/qa-bundle` | 2.0.0, dev only, MIT. The review page `/_dev/screenshots` needs `ext-gd`, which only the `frankenphp_dev` image installs. | https://repo.packagist.org/p2/tacticmedia/qa-bundle.json |
| `friendsofphp/php-cs-fixer` | 3.95.27 | https://repo.packagist.org/p2/friendsofphp/php-cs-fixer.json |
| `react`, `react-dom`, `@types/react`, `@types/react-dom` | 19.3.0 | https://www.npmjs.com/package/react |
| `react-router` | 8.4.0, Data Mode. Peers `react` and `react-dom` `>=19.2.7`. | https://www.npmjs.com/package/react-router |
| `vite`, `@vitejs/plugin-react`, `typescript` | 8.3.1, 6.1.1, 7.0.2 | https://www.npmjs.com/package/vite |
| `@pierre/diffs` | 1.5.2, exact pin, Apache-2.0 | https://www.npmjs.com/package/@pierre/diffs |
| `@primer/react`, `@primer/primitives`, `@primer/octicons-react`, `@primer/css` | 38.40.0, 11.10.0, 19.38.0, 22.3.2 | https://www.npmjs.com/package/@primer/react |
| `@primer/behaviors` | 1.11.0, the version that `@primer/react` installs, for `isEditableElement()` and `isMacOS()` | https://www.npmjs.com/package/@primer/behaviors |
| `react-markdown`, `remark-gfm`, `vitest` | 10.1.0, 4.0.1, 5.0.2 | https://www.npmjs.com/package/react-markdown |
| `@playwright/test`, `ajv`, `ajv-formats`, `@types/node` | 1.63.0, 8.20.0, 3.0.1, 24.19.1 | https://www.npmjs.com/package/@playwright/test |
| `@tacticmedia/qa-capture` | 0.1.0, exact pin, dev only: the capture fixture of the journeys | https://www.npmjs.com/package/@tacticmedia/qa-capture |
| `web-tree-sitter`, `tree-sitter-javascript`, `tree-sitter-typescript`, `tree-sitter-python`, `tree-sitter-go`, `tree-sitter-rust` | 0.27.0, 0.25.0, 0.23.2, 0.25.0, 0.25.0, 0.24.0, exact pins in `symbols/package.json`. web-tree-sitter 0.27.0 loads grammars of ABI 13 to 15. tree-sitter-typescript 0.23.2 and tree-sitter-rust 0.24.0 are ABI 14, the others ABI 15. tree-sitter-rust 0.24.1 and 0.24.2 are GitHub tags without an npm release. Install with `--ignore-scripts`: the install scripts of the grammars compile Node bindings, which need Python and a C compiler. | https://www.npmjs.com/package/web-tree-sitter |
| `tree-sitter-java`, `tree-sitter-c-sharp`, `tree-sitter-ruby`, `tree-sitter-bash`, `tree-sitter-scala`, `@tree-sitter-grammars/tree-sitter-zig`, `@tree-sitter-grammars/tree-sitter-lua` | 0.23.5, 0.23.5, 0.23.1, 0.25.1, 0.24.0, 1.1.2, 0.4.1, exact pins in `symbols/package.json`. The Java, Ruby, Scala, and Zig grammars are ABI 14, the C#, Bash, and Lua grammars ABI 15. The C# file is `tree-sitter-c_sharp.wasm`. The Zig grammar requires a member in a container, also on its master branch, so `struct {}` is a parse error. The unscoped `tree-sitter-zig` 0.2.0 is another grammar and ships no `.wasm` file. The grammars declare the native `tree-sitter` as an optional peer dependency, so `npm install` needs no `--legacy-peer-deps`. | https://www.npmjs.com/package/tree-sitter-java |
| Node.js in the `symbols` image | 24.21.0 from `node:24-trixie-slim`. It runs the TypeScript sources directly, with type stripping. | `node --version` in the `symbols` container |

## Known gotchas

### Recipes and bundles

- **HWIOAuthBundle.** Its recipe is a contrib recipe. Version 2.5.0 requires `symfony/form`, so keep Form. It has no PKCE. Its `csrf` option defaults to `false`: set it to `true`. Its failure handler stores no error in the session, so `Security\SignInErrorListener` stores it for `/login`. It throws plain `AuthenticationException`s, for example for a wrong `state`, so `translations/security.en.xlf` maps their message key to "Signing in with GitHub failed. Try again."
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
- **Mercure hub URL.** Always set `hubs.default.url`. When it is unset, the bundle compiles `FrankenPhpHub`, which calls `mercure_publish()`, a function that `10-app.ini` disables. See `$builtinHub = !isset($hub['url'])` in `MercureExtension.php`.
- **`mercure_publish()` is disabled.** Only FrankenPHP defines it, and the Mercure bundle changes its configuration tree when it exists. `docker/frankenphp/conf.d/10-app.ini` sets `disable_functions = mercure_publish`, so FrankenPHP and the CLI build the same container and write the same `config/reference.php`.
- **Mercure 1.x.** FrankenPHP 1.12.7 bundles Mercure 0.24.2. The first FrankenPHP release with Mercure 1.x needs these changes in one step:
  - `protocol_version: '1.0'` on the hub in `config/packages/mercure.yaml`;
  - the `iss`, `sub`, and `client_id` claims in `mercure.hubs.default.jwt.claims`. `aud` defaults to `public_url`, but the app publishes to the internal `MERCURE_URL`, so pin the hub's `resource_identifier` or set `aud`, as the bundle's `claims` option describes;
  - `issuer` blocks in the Caddyfile. Mercure 1.0 refuses the top-level `publisher_jwt` and `subscriber_jwt` directives unless `protocol_version_compatibility 8` is set;
  - `cookie_name: mercureAuthorization`, because the 1.0 default `__Secure-mercure_access_token` cannot be set over plain HTTP.
- **Reprise recipe.** It re-creates `assets/app.js` and `assets/styles/app.css`, and edits `templates/base.html.twig`.
- **symfony/rate-limiter 8.1.** It has no Flex recipe. `framework.rate_limiter` refuses a `lock_factory` while `framework.lock` is off, so `config/packages/rate_limiter.yaml` sets `lock_factory: null`, and `Kernel::process()` gives each limiter the advisory-lock `LockFactory`. The policies read `microtime()`, not the Symfony clock. `tests/Support/Clock/rate-limiter-time.php`, which `tests/bootstrap.php` loads first, shadows it with the global clock, so `ClockSensitiveTrait::mockTime()` drives the limiters. A token bucket adds its whole `amount` once per interval.
- **symfony/webhook.** The recipe route prefix is `/webhook`, so the GitHub endpoint is `/webhook/github`. A request parser that returns `null` makes Symfony answer 406. Return a `RemoteEvent` for every verified delivery.

### Docker and PostgreSQL

- **PostgreSQL 18 data path.** The image stores data under `/var/lib/postgresql/18/docker`. Mount the volume at `/var/lib/postgresql`, not at `/var/lib/postgresql/data`. The skeleton defaults to PostgreSQL 16.
- **Compose and .env.** Compose reads the Symfony `.env` for interpolation. Override `LOCAL_REPOSITORIES_HOST_DIR` through the shell environment.
- **Compose environment and test processes.** Real environment variables win over `.env` files. A variable in the compose `environment:` list of `php` also reaches the `test` and `e2e` processes in that container. Put per-environment values into `.env.dev`, `.env.test`, and `.env.e2e`.
- **Root in the container.** The `php` container runs as root, so permission-based tests prove nothing. Compare bytes and modification times.
- **One database role.** Do not add init scripts under `/docker-entrypoint-initdb.d/`. A second role needs the facts in [database-hardening.md](../backlog/database-hardening.md).
- **Image build context.** `.dockerignore` excludes `docs/` and every `*.md` file. `DocsCatalog` reads `docs/user/` and `docs/specs/schemas/` at run time, so a production stage that copies the source must include both, or `/docs` answers 500.
- **Worker cache.** The compose `worker` sets `APP_CACHE_DIR=var/cache/worker`, so it builds its own container in `var/cache/worker/dev`. A rebuild of `var/cache/dev` by the `php` container removes container files that a worker on the shared cache still loads, and such a worker dies with its message delivered until the 15-minute redelivery.
- **A new package in worker mode.** A FrankenPHP worker keeps the Composer autoloader that it loaded at its start. After `composer install` adds a package, run `docker compose restart php`, or the dev app answers with "Class not found". Tests run in new processes and do not show it.

### CI and act

- **Host path.** `act --bind` mounts the working directory at its host path, so the compose bind mount `.:/app` of the job resolves on the host daemon. That is why `make act` binds a copy in `var/act/workspace/` and never the checkout.
- **One run per daemon.** act names the job container after the workflow and the job, `act-QA-qa-<hash>`, and removes a container with that name before it starts. `bin/act-run` refuses to start while that container runs.
- **Root on a Linux runner.** The `php` container writes into the bind mount as root, and Docker creates a missing bind-mount source, for example `var/local-repositories`, as root. The workflow creates `var/` first, so that the host-side `make ui` can write `var/ui/`. Docker Desktop on macOS maps the owner, so `make act` does not show this.
- **Composer schema.** Composer checks `composer.json` against its schema at every load. A schema error fails `composer audit` and every other Composer command, not only `composer validate`.
- **act caches.** act clones every action of the workflow into `~/.cache/act`, also an action whose step it skips. Its cache server speaks the v1 cache API, which `actions/cache` uses while `ACTIONS_CACHE_SERVICE_V2` is unset.
- **act artifacts.** The artifact server of act 0.2.89 accepts `actions/upload-artifact` up to v5.0.0. v6.0.0 bundles `@azure/storage-blob` 12.29, whose upload request fails the signature check of act with "Error unauthorized". v7 also sends the field `mime_type`, which act refuses. The workflow pins v5.0.0, so the step that runs on GitHub is the step that `make act` verifies.

### PHPStan level 8

- phpstan-doctrine needs `objectManagerLoader`: `tests/object-manager.php`.
- phpstan-doctrine finds a type descriptor by the DBAL type class, so DQL results of `IdentifierType` and `StringValueType` columns are `mixed`. A descriptor for the class gives `Identifier` for every ID column, and `doctrine.columnType` then fails on each typed ID property. A descriptor per type name needs a subclass of `DefaultDescriptorRegistry`, which PHPStan reports as `phpstanApi.class`.

### Mocked UI suite

- The sticky file header covers the top rows of the code, and a row renders only after the scroll that brings it near the window. `dragFromPlus()` centers the line number, then waits until the line number and the "+" receive the pointer.
- The window scrolls the code. The code view loads in its own chunk, so a test that scrolls waits until the page has the height of the code.
- The scroll to the line of a `#L` hash moves the window once per frame until the row of the line is in view below the header, so it can still run when the row exists. Only user input stops it: a key, the wheel, a pointer press, or a touch. A test scrolls during it with a key, for example `page.keyboard.press('PageDown')`. The next frame undoes a scroll from `page.evaluate()`.
- A Playwright context denies clipboard writes. A test of a copy button grants `clipboard-read` and `clipboard-write`.
- `page.route` cannot stream a response, so `EventSource` is replaced by `addInitScript`.
- `context.route` also intercepts the imports of the `@pierre/diffs` worker. `page.route` is enough for `/api/v1`.
- The worker pool renders with `useTokenTransformer`, so each code token is an element with `data-char`, its UTF-16 offset in the line. A Cmd-click is `click({ modifiers: ['ControlOrMeta'] })` on such a token.
- `@pierre/diffs` draws no row until the worker pool of the page is ready, which takes seconds on a loaded host, and `onPostRender` also reports the empty render of the mount. A placeholder height stays until the view has a `[data-line]` row, as in `SidesDiff` of `CommitDiff.tsx`. A test waits for a row, never for a fixed time.

### Journeys and the screenshot review

- Each journey run empties `var/screenshots/` in its global setup, also a run of one spec. The notes and crops of the review stay in `var/review/`, so a note on a screen that a later run does not capture shows as stale in the prompt.
- `captureScreen()` waits only for its content, visible spinners, `aria-busy`, and the plain-text fallback of a lazy `MarkdownBody` (`data-markdown-pending`). A screen that loads more after that, for example a code view before its highlighting or a diff below the fold, waits for it in the spec before the capture.
- The `qa` routes exist only in `dev`, because `config/bundles.php` registers `TacticMediaQaBundle` only there. The resource `routing.controllers` of `config/routes.yaml` loads the routes of every autoconfigured controller with `#[Route]`, also the controller of the bundle, so a registration in `test` adds them to `test` whatever `config/routes/qa.yaml` says. The page has no authentication, so the `dev` firewall pattern contains `_dev` and no firewall runs for it.

### Test environments and front-end builds

- **Sessions.** The `test` environment uses `mock_file` sessions. A real browser must use the `e2e` environment.
- **Build output.** `npm run build` writes the bundles to `public/build`, and it and the Vite dev server write `public/entrypoints.json`, which the dev worker watches. The E2E suite uses its own build in `public/build-e2e`.
- **Server-sent events in a functional test.** `EventSourceHttpClient::stream()` ends with its first timeout chunk, and a timeout chunk that `isTimeout()` does not check throws "Idle timeout reached". Pass the whole wait as the stream timeout and check `isTimeout()`, as `HubIsolationTest` does.
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
- **Failed flush.** A failed flush closes the entity manager. Code that goes on, for example after a unique key violation, calls `ManagerRegistry::resetManager()`, which resets the injected `EntityManagerInterface` in place.
- **Advisory locks and transactions.** The lock store shares the database session of the ORM (D63). An aborted transaction refuses `pg_advisory_unlock` until its rollback, so release a lock only after the transaction inside it has ended. `CommandExecutor`, `transactional()`, and `wrapInTransaction()` roll back before they rethrow.
- **Messenger deduplication.** Do not use `DeduplicateStamp` with the advisory-lock store. An advisory lock belongs to one database session, so the worker cannot release a lock that the web process took.
- **Live-update payloads.** They carry IDs only. Never put a `RequestSummary`, a `Review`, or other data into a Mercure update.
- **Clone size.** Git keeps the objects of a fetch of fewer than 100 objects loose (`fetch.unpackLimit`), so `size-pack` alone misses them. `BareClone::objectSizeBytes()` adds `size` and `size-pack` of `git count-objects -v`.
- **Doctrine profiler in the worker.** In debug mode (`dev`, `test`), DoctrineBundle's `DebugMiddleware` keeps every statement of one Messenger message, with its parameters and a backtrace, until the message ends. A job that writes per item grows with each statement, so it writes in batches. See F12.
- **Database error messages.** `Doctrine\StripDatabaseErrorDetail` keeps only the first line of each driver error and drops the `PDOException`, so the `DETAIL`, `HINT`, and `CONTEXT` lines of PostgreSQL, which can hold row values, reach no exception and no log. Run the statement in `psql` in the `database` container to see them. See F45.
