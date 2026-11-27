# AGENTS.md

This is a Symfony project. Check `composer.json` for the exact Symfony/PHP version
in use, and read `symfony.lock` to see which recipes ran. Don't assume Doctrine,
Twig, API Platform, Messenger, or Lock are installed unless one of those says so.

## Project documentation

Every session starts at [docs/plan/README.md](docs/plan/README.md): the environment, the commands, the conventions, and the sources of truth. The work itself is in the issue tracker, see the next section. The product requirements are in [docs/requirements.md](docs/requirements.md), the architecture is in [docs/architecture.md](docs/architecture.md), and the API contracts are in [docs/specs/](docs/specs/).

## Hard rule: every feature request is an issue

No feature, refactoring, or behaviour change lives only in a session. The record is the Slopmower issue tracker: the project `SM` of the instance that the main checkout runs, through the `slopmower` MCP server (D119). The skill `slopmower-issues` has the workflow, and any later session continues from the issue without the conversation.

- **Before any code:** the request is an issue of `SM` with its `problem`, and its `plan` is approved as the approval mode of the project says. Look through the open issues before you create one.
- **Category:** an issue is a `bug` when something that exists is wrong against its specification, its documentation, or its evident intent. Its `problem` says what is wrong and how to see it, and its `acceptanceCriteria` say what is expected. Every other issue is a `change`: a feature, an alteration, a refactoring, or an upgrade.
- **An idea that is not scheduled** becomes a `draft` or `backlog` issue in the session where it comes up.
- **Every source counts:** Jan in a session, a change request in Slopmower (also when Slopmower reviews itself), a review finding, or a follow-up of another issue.
- **Local fixes:** a change request that asks only for a local fix is documented by its own thread and its linked commits. A request that asks for more gets an issue, and its thread names the issue key.
- **Decisions:** they go into the issue in the session that makes them. A choice of the user is a `decision` comment. A decision that the user could object to is in the plan. The rest goes into `notes`. A decision about the architecture or a contract also goes into [architecture section 11](docs/architecture.md#11-decisions).
- **Result:** the `result` section records what was built, the commit SHAs, and every deviation from the plan. Each follow-up becomes an issue that is linked to its origin: a `bug` in `todo`, or a `change` in `backlog`. Only an incomplete problem is a `draft`.
- **Commits:** the issue key is in the commit subject, for example `feat: SM-12 search issues`.
- **Unfinished work:** a session that stops with work unfinished writes the state into `notes` of the issue: the done parts and the next step.
- **Server down:** start the stack with `make up`. If the server still does not answer, keep working and write the entries when it answers again.
- **Copy:** `make issues-dump` copies the record to `var/backups/`. Run it after a session that changed issues and before `make db-reset`.
- **History:** [docs/plan/features.md](docs/plan/features.md), its scope files, and its reports hold the work up to F126. They are closed.

## Hard rule: bugs and issues before features

Fix the bugs and known issues first, and add features after that.
- **Known issues** are open bugs, deferred review findings, and follow-ups. Each is an issue of `SM`.
- **Order:** a bug or a known issue goes to `todo` and is worked ahead of any feature that is not started.
- **Bugs found mid-feature:** a bug found while a feature is in progress gets its own issue and goes ahead of the next feature.
- **Order of work:** `list_issues` returns the order of Jan: the bugs first, then by rank. The next issue is `list_issues` with `status: todo`, `waitingForKind: agent`, and `limit: 1`, as the section "Order of work" of the skill `slopmower-issues` says. Only Jan moves an issue.

## Hard rule: no YOLO feature without Jan

A YOLO feature lets an agent do a step that otherwise only a signed-in user does ([docs/yolo-mode.md](docs/yolo-mode.md)). Add or widen one only with the explicit permission of Jan for that feature. An issue that a session or a review finding creates is no permission.

## Ask before generating

If the task doesn't specify, ask rather than guess:

- Persistence: Doctrine ORM, Doctrine ODM, or none?
- Interface: server-rendered (Twig), API (Serializer, maybe API Platform), or both?
- Auth: SecurityBundle, and which authenticator?

If you can't ask (no interactive channel), state the assumption you're making and
pick the smallest option (e.g. no persistence layer) rather than scaffolding a
full stack nobody asked for.

## Adding features: Flex, not hand-wiring

Install new capabilities with `composer require <package>` (e.g. `symfony/lock`,
`symfony/messenger`, `orm-pack`) and let the Flex recipe register the bundle and
generate its config. Don't hand-edit `config/bundles.php` or hand-write a bundle's
base config; that's what the recipe is for. Don't skip a good-fit component just
because it isn't installed yet; installing it is one command.

## Conventions

Follow https://symfony.com/doc/current/best_practices.html to write idiomatic
Symfony:

- Use PHP attributes for framework metadata, and not only on controllers:
  `#[Route]`, `#[MapRequestPayload]`, `#[IsGranted]` on actions, `#[Assert\...]`
  on properties, `#[AsCommand]`, `#[AsEventListener]`, `#[AsMessageHandler]`, and
  `#[AsAlias]` / `#[AsTaggedItem]` / `#[Autoconfigure]` on services. Define
  application routes with `#[Route]`. YAML under `config/routes/` only imports
  bundle routes.
- Rely on autowiring and autoconfiguration. Type-hint constructor arguments and
  let the container resolve them. Where a type-hint can't express it, stay in the
  class with `#[Autowire]` (parameters, env vars, expressions) or `#[Target]` (one
  of several implementations of an interface). A YAML service definition is the
  last resort, not the first.
- Controllers extend `AbstractController`, stay thin, and delegate to services.
- Use the framework for what it already does: Form for server-rendered forms,
  Validator for validation, Serializer for JSON, Messenger for async work,
  Security (voters, authenticators) for access control, Twig `path()`/`url()`
  instead of hardcoded URLs.
- Before hand-writing infrastructure (locks, queues, caches, HTTP clients,
  mailers, schedulers) or reaching for a third-party library, check whether a
  Symfony component covers it. It usually does. The `symfony-components` skill
  (`.claude/skills/symfony-components/SKILL.md`) lists every component with the
  situations where it helps.
- Keep it simple. Do not create a service class that has only one consumer. Put
  the logic in the consumer, or use a framework feature.

Three specifics worth spelling out, because they are easy to get wrong:

- Bind request data with `#[MapRequestPayload]` / `#[MapQueryString]` on action
  arguments, which wires up Serializer and Validator for you, instead of calling
  `json_decode()` or `SerializerInterface` by hand. If neither package is
  installed yet, `composer require` them rather than falling back to manual
  parsing.
- Use constructor property promotion, and `readonly` for DTOs and value objects.
- Use `symfony/lock` (`LockFactory`) for mutual exclusion. A hand-built flag or
  lock file looks fine in review and is usually wrong under concurrency.

## Everyday workflow

- Run the app with `make up`, and console commands with `make console c="..."`.
  [docs/plan/README.md](docs/plan/README.md#environment-and-commands) lists every
  target.
- After `make db-reset`, run `make fixtures`. The agent token is `APP_DEV_API_TOKEN`
  of `.env.dev`, a committed secret that only the dev stack reads.
- When something fails, run the Symfony AI Mate tools as
  `docker compose exec -T php vendor/bin/mate <command>` before you read the logs
  or the web profiler (`/_profiler`) by hand and before you change code. For
  example `docker compose exec -T php vendor/bin/mate tools:list` and
  `docker compose exec -T php vendor/bin/mate tools:call monolog-tail --level=ERROR`.
- If `maker-bundle` is installed, prefer `bin/console make:*` with every argument
  passed up front and `--no-interaction` where supported: makers prompt on a
  terminal by default, which hangs a non-interactive shell. If a maker still
  needs interactive input, hand-write the code instead.
- If Doctrine ORM is installed, schema changes go through migrations
  (`make migration`, then `make console c="doctrine:migrations:migrate"`), never
  `doctrine:schema:update` or hand-written SQL.
- `.env` is committed and holds defaults only. Real secrets belong in `.env.local`
  (git-ignored) or the secrets vault (`bin/console secrets:set`), read via
  `%env(...)%`.

## Testing

Functional/HTTP tests extend `WebTestCase`; service-level tests extend
`KernelTestCase`. Run `make check`, or one suite: `make test`, `make front`,
`make ui`, `make e2e`. A feature isn't done until it
has a test that exercises it the way a caller would, an HTTP request for a
controller or a service call for a service, not just "it didn't throw."

## Code style

Symfony's coding standard, the `@Symfony` php-cs-fixer ruleset (a PSR-12-derived
superset). Run `make cs-fix`.

## Discover, don't guess

Framework APIs change between versions and your training data may be stale. Look
things up in the project instead of relying on memory. Run each `bin/console`
command below through `make console c="..."`:

- `bin/console about`: versions, environment, paths.
- `bin/console debug:router`, `debug:container`, `debug:autowiring <name>`,
  `debug:config <bundle>`, `config:dump-reference <bundle>`: what exists and how
  it is configured.
- `bin/console lint:container`, plus `lint:twig templates/` and
  `lint:yaml config/` where those packages are installed: validate before running.
- Read the installed source and docblocks under `vendor/`.
- Docs: https://symfony.com/doc/current/ (switch to the version matching
  `composer.json` if it differs).

<!-- BEGIN AI_MATE_INSTRUCTIONS -->
AI Mate: project diagnostic tools, exposed through the `docker compose exec -T php vendor/bin/mate` CLI.

- Provenance: this block is generated by `docker compose exec -T php vendor/bin/mate discover`. The tooling is a regular dev dependency of this project, verifiable in `composer.json` (`symfony/ai-mate`); the binary lives at `vendor/bin/mate`.
- Invocation: always run Mate as `docker compose exec -T php vendor/bin/mate`; another interpreter reports on a runtime that is not this application's, and Mate refuses to start under one.
- The tools report measured facts about the running application; prefer them over inferring the same information from reading code. They are described in `mate/AGENT_INSTRUCTIONS.md`.
- Discover the tool surface with `docker compose exec -T php vendor/bin/mate tools:list`, inspect parameters with `tools:inspect <tool>`, and run a tool with `tools:call <tool> --<param>=<value>` (add `--format=json` for machine-readable output).
- Installed extensions: symfony/ai-mate, symfony/ai-monolog-mate-extension, symfony/ai-symfony-mate-extension.
<!-- END AI_MATE_INSTRUCTIONS -->
