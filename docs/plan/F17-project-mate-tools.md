# F17 Project Mate tools

Workflow, conventions, and status: [feature track](features.md). Split from F05. Developer tooling only: no product change.

## Goal

A coding agent that works on this repository reads the reviews and the event log of the dev database through Mate, instead of writing SQL by hand.

## Decisions

- **Mechanism (symfony/ai-mate 0.14.0, checked in the installed source).** A project tool is a public method with `#[Symfony\AI\Mate\Attribute\MateTool]` on a class in `mate/src/` (namespace `Mate\`, already in `autoload-dev`, and `extra.ai-mate.scan-dirs` already names `mate/src`). Mate discovers it on every run. `Mcp\Capability\Attribute\McpTool` is not used by Mate.
- **Database access.** The Mate container holds no application service. `mate/config.php` registers a `Doctrine\DBAL\Connection` built with `DriverManager::getConnection()` from `%env(DATABASE_URL)%`, parsed with `Doctrine\DBAL\Tools\DsnParser` (DBAL 4 has no `url` option). `mate.env_file` is set to the string `.env`, because the `php` container has no `DATABASE_URL` variable. Check the DSN parsing against the installed DBAL before relying on it.
  - Do not register the tool classes in `mate/config.php`: Mate registers each handler as a public autowired service before it loads that file, and a redefinition there makes it private.
- **Tools**, read-only, in one class `Mate\ReviewTools`:
  - `review-list`: `repository?` (case-insensitive substring of the repository name), `state?` (`open` or `closed`), `limit` (default 20, at most 100). Per review: `id`, `tenantId`, `repository`, `title`, `state`, `commitSha`, `lastEventSeq`, `createdAt`, and the number of requests. Newest first.
  - `review-events`: `review` (ULID, required), `request?` (ULID: only events whose `request_ids` contain it), `afterSeq?`, `type?` (a prefix such as `request.`), `limit` (default 50, at most 500). Per event in `seq` order: `seq`, `type`, `occurredAt`, the actor (`kind`, `userId`, `agentName`), `requestId`, `requestIds`, and the decoded `payload`.
  - IDs are upper-case ULIDs, as in the API. Payloads hold user and agent text, so both tools return `ResponseEncoder::encodeUntrusted()`, as the Monolog extension does.
  - No tenant filter: the tools read the dev database for the developer. They are dev dependencies and never run in production.
- **Agent instructions.** A project instructions file, `mate/INSTRUCTIONS.md`, named in `composer.json` `extra.ai-mate.instructions`, describes the two tools. `make mate c=discover` regenerates `mate/AGENT_INSTRUCTIONS.md` and the Mate block of `AGENTS.md`.
- **Quality gates.** PHPStan level 8 also analyses `mate/src`. php-cs-fixer excludes only the generated `mate/extensions.php`, so `mate/src` and `mate/config.php` follow `@Symfony`.

## Depends on

F05 (merged).

## In scope

- `mate/src/ReviewTools.php`, `mate/config.php`, `mate/INSTRUCTIONS.md`, `composer.json` (`extra.ai-mate.instructions`), the regenerated `mate/AGENT_INSTRUCTIONS.md` and `AGENTS.md` block.
- `phpstan.dist.neon`, `.php-cs-fixer.dist.php`.
- Docs: the developer guide (where it names Mate), `docs/plan/README.md` section "Environment and commands" (one row or line for the project tools).

## Out of scope

- Tools that write, or tools for other tables.
- Any change to the product MCP server (`symfony/mcp-bundle`, `src/Mcp/`).

## Acceptance tests

- **F** `tests/Functional/Mate/ReviewToolsTest.php` (`KernelTestCase`): it builds `ReviewTools` with the test container's DBAL `Connection`, writes a review with two requests and their events through the existing fixtures, then:
  - `review-list` finds the review by a repository name substring;
  - `review-events` returns the events in `seq` order, and `request` keeps only the events of that request.
- `make stan` and `make cs` cover `mate/src`.

## Verification

- `make check` is green.
- Manual check: `make mate c="tools:list"` lists both tools, and `make mate c="tools:call review-events --review=<id> --limit=5"` prints events of a dev review.
