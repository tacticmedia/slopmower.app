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

- Jan installs the packages himself with `composer require --dev` (2026-10-07). F05 adds no other wrapper than the make target.
- Add one make target, `make mate c="<arguments>"`, that runs `vendor/bin/mate <arguments>` in the `php` container, like `make console`.
- `mate/config.php` points the Monolog extension at the container's log directory.
- Skills that `mate discover` installs follow Mate's own layout. Their path is recorded in the report. They are committed when Mate expects them in the repository, and git-ignored when they are generated.
- `AGENTS.md` "Everyday workflow": when something fails, agents run the Mate tools as `docker compose exec -T php vendor/bin/mate <command>` (log tail, profiler) before reading files by hand. That is the invocation given to `mate init`, so it matches Mate's own block in `AGENTS.md`. `make mate c="..."` is the shortcut for people, in the `docs/plan/README.md` "Environment and commands" row.

## Depends on

M01. No contract change and no product behaviour change.

## In scope

- The make target and the two doc updates above. The packages, `mate init`, `mate/config.php`, and the skills come from Jan's install.
- A check that `make mate c="tools:list"` lists the Symfony and Monolog tools, and that `make mate c="tools:call monolog-tail --level=ERROR"` returns entries from the dev log.

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
