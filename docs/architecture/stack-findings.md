# Stack findings

Part of [the architecture document](../architecture.md). MCP SDK, Agent Skills, versions, front-end pipeline, local run, and git plumbing.

The phase 1 spikes, clones, transcripts, and most screenshots were in a temporary scratch directory. They are not in the repository. The screenshots that the main document uses are in this directory. Paths such as `spikes/...` or `tools/...` refer to that scratch directory.

## MCP: mcp/sdk and symfony/mcp-bundle

**Versions (checked 2026-09-29).**
- `mcp/sdk` v0.8.1, 2026-08-29 (https://repo.packagist.org/p2/mcp/sdk.json). 12 releases in 12 months; BC breaks in 0.2 through 0.8 (`CHANGELOG.md`). PHP `^8.1`. Experimental until 1.0 (`README.md:25`). Tier 3 per the SDK tier audit (`docs/sdk-tier.md`), server conformance 67/67. PHPStan level 6 (issue #492 "Raise PHPStan to level 8" open).
- `symfony/mcp-bundle` v0.14.1, 2026-09-26 (https://repo.packagist.org/p2/symfony/mcp-bundle.json). Developed in the symfony/ai monorepo (`src/mcp-bundle`). "This Bundle is experimental" (`README.md:8-11`). Configuration rewritten in 0.13. Requires `mcp/sdk ^0.8.1`, Symfony `^7.3|^8.0`.
- TypeScript SDK: `@modelcontextprotocol/sdk` 1.31.0 (v1 line); `@modelcontextprotocol/server` 2.2.0 (v2, stable, released with spec 2026-07-28).
- MCP spec: current revision 2026-07-28 (https://modelcontextprotocol.io/specification/versioning). It removes the handshake and sessions and adds `server/discover`.

**Transports and revisions (PHP SDK).**
- `StdioTransport`, `StreamableHttpTransport` (both eras), `StatelessHttpTransport` (2026-07-28 only), `InMemoryTransport`. No legacy HTTP+SSE.
- `ProtocolVersion` enum: 2024-11-05, 2025-03-26, 2025-06-18, 2025-11-25, 2026-07-28 (`src/Schema/Enum/ProtocolVersion.php:42-46`).
- Stdio serves the handshake era only, up to 2025-11-25 (`src/Server.php:57-62`). A 2026-07-28 `initialize` gets a counter-offer of 2025-11-25.
- Claude Code: v2 runtime asks only HTTP servers for 2026-07-28 unless `MCP_PROTOCOL_NEGOTIATION=auto` (https://code.claude.com/docs/en/mcp). So stdio on 2025-11-25 is what Claude Code uses by default.

**Features that we need, all present.** Tools with `inputSchema` generated from the method signature, docblock, `#[Schema]`, and backed enums; explicit `outputSchema`; `structuredContent` (a JSON object up to 2025-11-25; any JSON value, lists included, from 2026-07-28); `ToolAnnotations` (readOnly, destructive, idempotent, openWorld). Also resources, prompts, completions, progress, elicitation.

**Bundle.** Console command `mcp:server [name]` for stdio (`src/Command/McpCommand.php:29`). Route loader type `mcp` for HTTP. Autoconfigures `#[McpTool]` and the other SDK attributes to tags; a compiler pass builds the schemas at container compile time. No official Flex recipe (auto-generated recipe only adds the bundle; recipe PR symfony/recipes#1574 open), so `config/packages/mcp.yaml` and `config/routes/mcp.yaml` are hand-written. HTTP also needs a PSR-17 implementation (`nyholm/psr7`); stdio does not.

**Spike** (`spikes/mcp-symfony`, Symfony 8.1.7, PHP 8.5.10). Installs cleanly, `lint:container` passes. Verbatim `initialize`, `tools/list`, and `tools/call` transcripts in `transcripts/handshake.txt`. Generated schema for `list_requests(?RequestStatus $status, #[Schema(minLength: 1)] ?string $path)`:
```json
{"type":"object","properties":{"status":{"type":["string","null"],"description":"only return requests with this status","default":null,"enum":["open","claimed","done","wont_do",null]},"path":{"type":["null","string"],"description":"only return requests whose file path starts with this prefix","default":null,"minLength":1}}}
```
Warm console boot about 0.15 s per stdio server start.

**Problems found (none blocks the prototype).**
1. Error without `id` for a request before `initialize` (`Protocol.php:698-702`); breaks a client that probes with `server/discover` (not Claude Code's default).
2. No 2026-07-28 over stdio.
3. Input schema failures return JSON-RPC -32602, not `isError: true` (spec 2025-11-25 `server/tools.mdx:469-471`, SEP-1303).
4. Any exception other than `ToolCallException` returns -32603 "Error while executing tool" and loses the message.
5. Object (DTO) tool parameters do not work (`ReferenceHandler.php:237`); use scalars and enums.
6. HTTP needs an undeclared PSR-17 package.
7. No Flex recipe.
8. `initialize` advertises prompts, resources, logging, completions even when none exist.
9. `server/discover` after `initialize` returns -32600, not -32601.
10. A tool that writes to stdout (`echo`) corrupts the stream; no output-buffer guard.

**Mitigations.** Pin exact versions; thin adapter (one `#[McpTool]` method per operation: scalars in, application service call, array out); map domain exceptions to `ToolCallException`; PHPUnit test that validates each result against its `outputSchema` with `opis/json-schema` (already a dependency); stdio wire-level functional test that fails on any non-JSON-RPC stdout line; ban `echo`/`var_dump` in adapters.

**Second runtime (TypeScript SDK).** Not needed: no required feature is missing in PHP. Estimated cost if needed later: schemas in two languages, two toolchains in distribution and CI, 0.15 s PHP boot per call, about 3-5 engineer-days initial.

## Agent Skills

Spec: https://agentskills.io/specification (no version or date). Layout `skill-name/SKILL.md` plus optional `scripts/`, `references/`, `assets/`. Required frontmatter: `name` (1-64 chars, `a-z0-9-`, no leading, trailing, or double hyphen, equals the directory name), `description` (1-1024 chars, what and when). Optional: `license`, `compatibility`, `metadata`, `allowed-tools` (experimental). Body under 500 lines / about 5000 tokens. Claude Code loads project skills from `.claude/skills/<name>/SKILL.md` (https://code.claude.com/docs/en/skills). The cross-client convention `.agents/skills/` exists but is not mandatory. The reference validator `skills-ref` is a Python tool, so we validate the frontmatter with our own PHPUnit test instead.

## Stack facts (verified 2026-09-29)

| Item | Version | Released | Source |
|---|---|---|---|
| PHP | 8.5.11 (local 8.5.10) | 2026-09-24 | https://www.php.net/releases/index.php?json&version=8 |
| Symfony | 8.1.7; 8.2.0 due 11/2026; 8.1 end of life 01/2027 | 8.1: 05/2026 | https://symfony.com/releases/8.1.json, https://symfony.com/releases/8.2.json |
| nikic/php-parser | 5.9.0 | 2026-09-13 | https://repo.packagist.org/p2/nikic/php-parser.json |
| phpstan/phpstan | 2.2.16 | 2026-09-25 | https://repo.packagist.org/p2/phpstan/phpstan.json |
| phpunit/phpunit | 13.3.5 | 2026-09-25 | https://repo.packagist.org/p2/phpunit/phpunit.json |
| symfony/panther | 2.4.0 | 2026-01-08 | https://repo.packagist.org/p2/symfony/panther.json |
| php-webdriver/webdriver | 1.16.0 | 2025-12-28 | https://repo.packagist.org/p2/php-webdriver/webdriver.json |
| dbrekelmans/bdi | 1.4.1 | 2025-11-03 | https://repo.packagist.org/p2/dbrekelmans/bdi.json |
| friendsofphp/php-cs-fixer | 3.95.27 (supports 8.5 syntax) | 2026-09-22 | https://repo.packagist.org/p2/friendsofphp/php-cs-fixer.json |
| humbug/box | 4.7.0 (cannot co-install with Symfony 8: needs finder ^6.4\|^7) | 2026-03-18 | https://repo.packagist.org/p2/humbug/box.json |
| FrankenPHP | 1.12.7 | 2026-08-07 | https://github.com/php/frankenphp/releases/tag/v1.12.7 |
| static-php-cli | 2.8.5 | 2026-04-18 | https://github.com/crazywhalecc/static-php-cli/releases/tag/2.8.5 |
| symfony/ux-react | 3.5.1 | 2026-09-19 | https://repo.packagist.org/p2/symfony/ux-react.json |
| symfony/reprise | 1.3.0, stable since 1.0.0 (CHANGELOG: "Stable release!") | 2026-09-26 | https://repo.packagist.org/p2/symfony/reprise.json |
| pentatrion/vite-bundle | 8.2.4 | 2026-03-22 | https://repo.packagist.org/p2/pentatrion/vite-bundle.json |
| sensiolabs/typescript-bundle | 0.2.2 (`.ts` only, no TSX) | 2025-08-28 | https://repo.packagist.org/p2/sensiolabs/typescript-bundle.json |
| vite / react / typescript / @vitejs/plugin-react | 8.3.1 / 19.3.0 / 7.0.2 / 6.1.1 | 2026-09 | `npm view` |
| tree-sitter CLI, web-tree-sitter / tree-sitter-php | 0.27.0 / 0.25.0 (GitHub; npm 0.24.2) | 2026-08-30 / 2026-09-24 | GitHub releases |
| git (local) | 2.55.0 | - | `git --version` |

Panther 2.4.0 resolves with PHPUnit 13.3.5 and Symfony 8.1 (dry run); its PHPUnit extension API exists unchanged in PHPUnit 13.3.5. Runtime is UNVERIFIED (no chromedriver download was allowed).

**php-parser.** Parses PHP 8.5 (`PhpVersion::getNewestSupported()` = 8.5; pipe, clone-with, `(void)`, `#[\NoDiscard]`, const attributes all parse). Use `(new ParserFactory())->createForNewestSupportedVersion()` + `NameResolver`. Nodes carry `startLine`, `endLine`, `startFilePos`, `endFilePos` (closed ranges); node range includes attributes but not the doc comment; signature line = `$node->name->getStartLine()`. Spike symbol table for Command.php: 1 class, 3 class constants, 14 properties, 41 methods; `run()` = lines 231-286, doc comment from 217. UnitOfWork.php (3534 lines, 133 symbols): parse 15 ms, parse + resolve + collect 22 ms (no Xdebug); 35/53 ms with Xdebug on.

**tree-sitter.** No maintained PHP binding (only third-party, stale). Options later: CLI through Symfony Process, or a Node sidecar with web-tree-sitter. Extension point now: a `SymbolExtractor` interface with a tagged iterator.

**Front-end pipeline.** AssetMapper cannot compile JSX/TSX (Symfony 8.1 docs frontend.rst, asset_mapper.rst). symfony/ux-react mounts React islands from Twig through Stimulus; it needs Vite/Rsbuild (Reprise) or Encore for JSX and does not support lazy components; it does not fit a client-routed SPA. Encore is in low-maintenance mode (README). The phase 1 draft recommended a plain Vite SPA built into `public/build` with `build.manifest: true`; Symfony serves the JSON API and one shell page that reads `.vite/manifest.json` (spike: build in 55 ms, shell and asset served). Symfony docs: "If you want to use a front-end framework ... we recommend using their native tools and using Symfony as a pure API" (frontend.rst 8.1). The main document now uses Symfony Reprise instead (decision D16): it renders the entry tags from `entrypoints.json` and points them at the Vite dev server in development.

**Local run.** The main document's design runs as one Docker Compose instance (`make up`), not as a per-repository launcher. These findings stay as research. Spike `bin/review` launcher: `REVIEW_REPO=$(git rev-parse --show-toplevel)`; `serve` runs `PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:$PORT -t app/public app/router.php`; `mcp` runs the console stdio command. The app reads `#[Autowire(env: 'default::REVIEW_REPO')]`. Verified from a subdirectory of another repository; 4 parallel 1 s requests took 1.09 s with 4 workers. Router script must return `false` for existing files (Symfony Runtime needs `SCRIPT_FILENAME`). Symfony CLI works too (env var reaches FPM) but writes pid/log files under the home directory and allows one server per app directory, so two repositories cannot be reviewed at the same time. PHAR (hand-built, 7.6 MiB) serves UI and MCP after four fixes (stub sets `SCRIPT_FILENAME`; `resource: '../src'` without trailing slash; cache dir outside the PHAR; stub streams `public/build`). FrankenPHP embed gives one 166-172 MB binary; `php-server` changes the cwd, so the repository path must come from an env var.

**Git plumbing (tested).**
- `git update-ref <ref> <new> <old>` refuses with exit 128 when the ref is not at `<old>`; zero OID as `<old>` means "must not exist". `--stdin` transactions are all-or-nothing.
- `hash-object -w` + temporary `GIT_INDEX_FILE` (`read-tree`, `update-index --cacheinfo`, `write-tree`) + `commit-tree -p` + `update-ref new old` writes a commit on a custom ref with no change to the working tree or the real index.
- `cat-file --batch` / `--batch-command --buffer` for a long-running reader; `ls-tree -r --long`.
- Custom refs are not pushed or fetched by default; explicit refspec `refs/reviews/*:refs/reviews/*`; fetch into a separate namespace to avoid overwriting local data.
- `merge=union` keeps both sides' lines (ours, then theirs); identical lines appear once, so every line needs a unique id; worktree-free `merge-tree` ignores `.gitattributes` unless told.
- `git notes` are keyed by commit SHA and lost on rebase unless `notes.rewriteRef` is set: weak for review data.
- Two concurrent writers x 25 appends: 50/50 events, linear history, CAS failures retried; without random backoff one writer starved for 25 attempts. Use random backoff and a retry limit.
- Reflog is not written for `refs/reviews/*` by default (`core.logAllRefUpdates=true` covers heads, remotes, notes, and HEAD).
