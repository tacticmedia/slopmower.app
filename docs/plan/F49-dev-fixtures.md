# F49 Development fixtures

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-14: development fixtures, so that an agent does not trip over `SLOPMOWER_TOKEN` after every database reset. Jan chose Foundry.

## Goal

- `make fixtures` makes the dev stack ready for an agent: the local user, an agent token with a secret that never changes, and this repository.
- The fixture set `dev` is idempotent, so a second load changes nothing.
- Foundry is in place for a later `demo` set and for the test helpers.

## Facts

- The dev database has one token, with the old `hr_` prefix, expired and scoped to one repository. `ApiTokenSecret::PATTERN` refuses `hr_`.
- `make db-reset` deletes every token. A new one needs a sign-in, Settings, and a restart of the agent.
- zenstruck/foundry v2.14.1 (2026-10-08):
  - `foundry:load-fixtures [names]` loads the stories that have `#[AsFixture(name:, groups:)]`.
  - `--append` skips the database reset.
  - `--no-transaction` loads the stories without a transaction.
  - It requires `fakerphp/faker ^1.24`.
- `CommandExecutor::transactional()` refuses an open transaction, so `EventsCommitted` follows a real commit. `FetchRepository::queueClone()` refuses one too.
- `TenantSqlFilter` throws `MissingTenantContext` on an ORM query without a tenant context.
- `LocalUserProvisioner::provision()` finds or creates the tenant and user of a local login, across tenants, inside `TenantFilter::suspend()`.
- `RepositoryController::connectLocal()` holds the local connect logic. S02 folded the service `ConnectLocalRepository` into the controller, because it had one consumer.
- Claude Code expands `${VAR:-default}` in the `url` and `headers` of `.mcp.json`.

## Decisions

D73 in [architecture section 11](../architecture.md#11-decisions).

- **Foundry for dev and test fixtures.** It is a dev package. `config/services.yaml` excludes `../src/{Factory,Story}/` from the `App\` resource, and `when@dev` and `when@test` register them, so prod has no class that extends Foundry.
- **Event data only through the command services.** A story writes reviews and requests through `ReviewCommands` and `RequestCommands`, never through a factory. Factories exist only for rows that are not event data: tenant, user, API token, repository.
- **Load without a transaction.** `make fixtures` runs `foundry:load-fixtures dev --append --no-transaction`. The executor keeps its rule.
- **The user comes from `LocalUserProvisioner`.** The story then sets the tenant context before any factory call. So the dev user is the user of "Sign in as jan", and no new `TenantFilter::suspend()` site exists.
- **A committed dev token.** `.env.dev` holds `APP_DEV_API_TOKEN`, one generated `sm_` secret. Only the `dev` environment reads `.env.dev`, and the dev stack listens on `127.0.0.1`. The token has all repositories and no expiry, and its name `Claude Code (dev)` is the agent name.
- **The token step:** an active token with the hash stays; a revoked or expired one is removed and created again; a missing one is created. The token ID stays stable while the row exists, so an MCP session survives a second load.
- **The repository step:** `ConnectLocalRepository` connects `APP_DEV_REPOSITORY`, default `holistic-review`. A directory that is not a local candidate is skipped with a note, for example in a worktree stack with an empty mount.
- **On demand only.** Nothing loads fixtures by itself.
- **`.mcp.json` stays local.** The docs show the URL of the stack and the header `Bearer ${SLOPMOWER_TOKEN:-<APP_DEV_API_TOKEN>}`.

## Depends on

F47 (the `sm_` token prefix).

## In scope

1. `composer require --dev zenstruck/foundry`, with its Flex recipe.
2. `config/services.yaml`: the exclude and the `when@dev` and `when@test` resources.
3. `src/Application/ConnectLocalRepository.php` from `RepositoryController::connectLocal()`, with the lock, the `findLocal()` check, `UsageLimits::checkNewRepository()`, and `queueClone()` after the commit. The controller calls it.
4. Factories in `src/Factory/`: `TenantFactory`, `UserFactory` (named constructor `local`), `ApiTokenFactory`, `RepositoryFactory` (named constructor `local`).
5. `src/Story/DevStory.php`, `#[AsFixture(name: 'dev')]`: the user, the token, and the repository steps.
6. `.env.dev`: `APP_DEV_API_TOKEN` and `APP_DEV_REPOSITORY`. `.env.test`: test values.
7. `Makefile`: target `fixtures`; the `db-reset` help names it.
8. Docs: `docs/developer-guide.md` (a fixtures paragraph, the namespace map, the ledger row "Single-consumer services"), `docs/plan/README.md` (environment and commands), `AGENTS.md` (everyday workflow), D73.

## Out of scope

Candidates in [features.md](features.md#candidates):

- A `demo` set with the data of every view, for qa-bundle screenshots.
- The test helpers of `tests/Support/` on the Foundry factories.

## Acceptance tests

- `tests/Functional/Fixture/DevStoryTest.php`, a KernelTestCase that loads the story without a transaction:
  - After `dev`, `GET /api/v1/me` with the bearer `APP_DEV_API_TOKEN` gives 200 with the user of `APP_LOCAL_SIGN_IN`.
  - A second load keeps one token row with the same ID, one user, and one repository.
  - A revoked token is replaced, and the bearer request gives 200 again.
  - A local candidate named `APP_DEV_REPOSITORY` is `cloning`, and one `CloneRepositoryJob` is queued. Without one, the step is skipped.
  - A malformed `APP_DEV_API_TOKEN` fails.
- The R3 tests of a local connect stay green.

## Verification

- `make check` is green.
- On the main stack: `make fixtures` twice, and the second load changes nothing. `curl -H "Authorization: Bearer <token>" http://localhost:<HTTP_PORT>/api/v1/me` gives 200.
- After the local `.mcp.json` is fixed and Claude Code restarts, `claude mcp list` shows `slopmower` connected, and `list_reviews` works.
