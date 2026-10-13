# F47 Product name Slopmower

Workflow, conventions, and status: [feature track](features.md). Jan named the product on 2026-10-13: Slopmower, GitHub repository `tacticmedia/slopmower`. No deployment is live, so every identifier changes in place, without a migration and without a fallback.

## Goal

- Every text, identifier, and contract of the repository uses the name Slopmower.

## Decisions

- **Display name.** "Slopmower", one word. A sentence that counted reviews by product name names the review: "a Slopmower review", "Work on Slopmower review <id>".
- **Identifiers.** `slopmower` replaces `holistic-review` and `holistic_review`, and `SLOPMOWER_TOKEN` replaces `HOLISTIC_REVIEW_TOKEN`:
  - MCP server name and `serverInfo.name`, the bundle service IDs `mcp.server.slopmower.*`, and the route `_mcp_endpoint_slopmower`;
  - the skill name and its install directory `.claude/skills/slopmower/`;
  - the Bearer realm, the pin refs `refs/slopmower/`, the live update ID `urn:slopmower:<tenant>:<seq>`, the JSON Schema `$id` base `https://slopmower.invalid/schemas/v1/`, and the `localStorage` key prefix `slopmower.`;
  - the npm package names, the act compose project `slopmower-act`, the GitHub App slug examples, and the database role names of the hardening backlog.
- **Token marker.** API token secrets start with `sm_`. The secret keeps 46 characters and the prefix 9.
- **Example host.** `slopmower.example` replaces `hr.example` in specs, fixtures, and tests.
- **Kept.** Paths of this checkout on disk, `/Users/janklan/dev/holistic-review` in report S14 and `var/local-repositories/holistic-review` in report F04, name directories that exist.
- **Contract.** D14 in [architecture section 11](../architecture.md#11-decisions). [Specs section 8](../specs/README.md#8-versioning-and-compatibility) item 10 lists it as an amendment of v1 before the first release.

## Depends on

None.

## In scope

Every tracked file that names the product: `src/`, `tests/`, `assets/`, `templates/`, `config/`, `mate/`, `symbols/package.json`, both npm lock files, `package.json`, `.env.test`, `.env.e2e`, `.env.prod.dist`, `.github/act.env`, `README.md`, `AGENTS.md`, and `docs/`. `src/Mcp/BundledSchemas.php` comes from `app:schema:bundle`.

## Out of scope

- The name of this checkout directory and of its compose project. A rename of the directory changes the default compose project name, so the stack starts with new volumes.
- Git-ignored local files: `.mcp.json`, the installed skill copy in `.claude/skills/`, `.claude/orchestration/`, `.idea/`.
- The git remote and the GitHub App registration.

## Acceptance tests

Existing tests with the new names:

- **U, `SkillFrontmatterTest`:** "name is slopmower, the name of the install directory".
- **F, `McpHandshakeTest`:** `serverInfo.name` is `slopmower`.
- **F, `BearerAuthenticationTest`, `McpAuthTest`, `MeEndpointTest`, `ApiErrorModelTest`:** the challenge is `Bearer realm="slopmower"`.
- **F, `BareCloneFetchTest`, `PendingShaFetchTest`, `StartReviewTest`, `ConcurrentReviewsTest`:** pins under `refs/slopmower/`.
- **F, `PublishAfterCommitTest`:** the update ID is `urn:slopmower:<tenant>:<seq>`.
- **F, `BundledSchemasUpToDateTest`:** the bundled schemas match `docs/specs/schemas/`.
- **Vitest, `mcpConfig.test.ts`, `skillInstallCommand.test.ts`, `promptSnippet.test.ts`:** server `slopmower`, `${SLOPMOWER_TOKEN}`, `.claude/skills/slopmower/SKILL.md`, "Work on Slopmower review <id>".
- **UI and E2E:** "Sign in to Slopmower", the agent prompt, the GitHub configure link.

## Verification

- `git grep -i -E 'holistic|hr_|hr\.example'` lists only the two kept paths, this file, and its report.
- `make check` is green, with `make act`, because `package.json` and the lock files are in `ACT_INPUTS`.
