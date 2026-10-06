# F05 Symfony AI Mate for coding agents

Workflow, conventions, and status: [feature track](features.md).

## Goal

Coding agents that work on this repository read the logs, the profiler, and the compiled container through Symfony AI Mate tools, not by reading `var/log/dev.log` by hand. Jan asked for it on 2026-10-07.

## Facts

- Symfony AI Mate (`symfony/ai-mate`) is a CLI that gives coding agents project-aware tools. Its extensions are Composer packages: `symfony/ai-symfony-mate-extension` (container, profiler) and `symfony/ai-monolog-mate-extension` (log search and tail).
- Mate no longer runs an MCP server: `serve`, `stop`, and the generated `mcp.json` are gone. Agents run commands such as:
  - `vendor/bin/mate tools:list` and `tools:inspect`;
  - `tools:call monolog-search --level=ERROR --from="-1 hour"`;
  - `tools:call symfony-profiler-list --limit=1`.
- `vendor/bin/mate init` writes `mate/config.php`. `mate discover` enables the extensions and installs their skills; the Composer plugin runs it after an install.
- This project runs PHP and Composer only in the `php` container through `make` (D33). Today `AGENTS.md` says: "When something fails, read `var/log/dev.log` and the web profiler (`/_profiler`) before changing code."
- None of these packages is installed today.

## Decisions

- Install the three packages as dev dependencies at their latest stable versions. Check Packagist first, and record any conflict with the exact pins (`mcp/sdk` 0.8.1, `symfony/mcp-bundle` 0.14.1).
- Add one make target, `make mate c="<arguments>"`, that runs `vendor/bin/mate <arguments>` in the `php` container, like `make console`.
- `mate/config.php` points the Monolog extension at the container's log directory.
- Skills that `mate discover` installs follow Mate's own layout. Their path is recorded in the report. They are committed when Mate expects them in the repository, and git-ignored when they are generated.
- `AGENTS.md` "Everyday workflow": when something fails, use the Mate tools through `make mate` (log search, profiler) before reading files by hand. `docs/plan/README.md` "Environment and commands" gets the `make mate` row.

## Depends on

M01. No contract change and no product behaviour change.

## In scope

- `composer require --dev` through `make composer`, `mate init`, `mate/config.php`, the make target, the skills, and the two doc updates above.
- A check that `make mate c="tools:list"` lists the Symfony and Monolog tools, and that `make mate c="tools:call monolog-search --level=ERROR"` returns entries from the dev log.

## Out of scope

- Custom Mate tools for this project, for example a tool that reads the event log. That is a separate candidate.
- Any change to the product MCP server at `/_mcp`.

## Acceptance tests

- No PHPUnit test: Mate is developer tooling and has no code of ours.
- `make check` stays green. PHPStan and php-cs-fixer must not scan the `mate/` directory unless Mate's config is code we own; exclude it if they fail on it.
- The verification commands below pass, and their output is in the report.

## Verification

- `make mate c="tools:list"`
- `make mate c="tools:call monolog-tail --limit=5"`
- `make mate c="tools:call symfony-profiler-list --limit=1"`
- `make check`
