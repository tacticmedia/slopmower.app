# Distribute the skill as a Claude Code plugin

Status: backlog. In the proof of concept the user installs the skill with one `curl` command from `/docs/skill/SKILL.md` and writes `.mcp.json` by hand, by decision D9.

## Why

- The `curl` install does not update. A user keeps an old skill after the contract gets new tools.
- The skill and the MCP server configuration are two manual steps.

## What

- A Claude Code plugin named `holistic-review` in a public repository, with the layout that the Claude Code plugin documentation at https://code.claude.com/docs/en/plugins describes:
  - the manifest `.claude-plugin/plugin.json` with name, version, and description;
  - `skills/holistic-review/SKILL.md`, the same file as `docs/user/skill/SKILL.md`;
  - `.mcp.json` with the `holistic-review` HTTP server and the header `Authorization: Bearer ${HOLISTIC_REVIEW_TOKEN}`.
- A marketplace file `.claude-plugin/marketplace.json` in the same repository, so that users add it with `/plugin marketplace add` and install the plugin from it.
- The plugin version follows the API version: plugin 1.x for API v1.
- The server URL differs between the hosted instance and self-hosted instances. Check whether Claude Code expands an environment variable in the plugin's `.mcp.json` URL. If it does, use a variable with the hosted URL as default. If it does not, the plugin covers the hosted instance only, and self-hosted users keep the manual steps.
- The user documentation shows the plugin as the first option and the manual steps as the second.

## Acceptance criteria

1. A test fails when the plugin's `SKILL.md` differs from `docs/user/skill/SKILL.md`.
2. The manifest and the marketplace file validate against the Claude Code plugin documentation at the time of the work.
3. A manual run installs the plugin in Claude Code, and the agent works on a review without a hand-written `.mcp.json`.
4. The user documentation describes the plugin install.

## Dependencies

- M23 public docs and downloadable skill.
- M15 MCP server.
