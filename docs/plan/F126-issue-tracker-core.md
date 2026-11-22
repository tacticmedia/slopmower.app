# F126 Issue tracker core

Workflow, conventions, and status: [feature track](features.md). From Jan in a session on 2026-11-22: "I want to add MCP-driven agentic issue tracker to ensure long-term memory of changes that is observable by a human and searchable by both human and agent", "the scope must include a skill for agents", and "create the minimal viable product and start using it to drive the product itself", "as early as possible". Decisions: [D116 to D119](../architecture.md#11-decisions).

## Goal

- An agent with an API token creates, plans, works, discusses, links, and ends issues of a project through MCP.
- The skill and the server instructions make an agent track a user request without being asked.
- After the merge, the remaining tracker work runs as issues of the project `SM` (D119).

## Depends on

- [F125](F125-platform-upgrade.md): this feature rebases on it before the merge.

## Facts

Verified on 2026-11-22:

- The event store is bound to a review: `CommandExecutor::execute(ReviewId ...)`, and `review_event.review_id` is not nullable. Issues do not use it.
- A tool is a definition in `docs/specs/mcp-tools.json`, a generated entry of `src/Mcp/BundledSchemas.php` (`app:schema:bundle`), and the camel-case method of `src/Mcp/McpTools.php`, which `ToolLoader` registers. `McpTools::run()` has the rate limit, the normalization, and the error mapping.
- `ToolsListContractTest::PATTERN_DEFINITIONS` maps each input name to one pattern for all tools: `id` and `review` are ULIDs. So the issue tools use the input names `project`, `issue`, `target`, and `reviewNote`.
- `error.schema.json` requires the request fields of `InvalidTransitionDetails` for `invalid_transition`, and `McpAssertions` checks every tool error against it. `InvalidInput::field()` needs no schema change.
- The SDK reads an explicit `null` argument as the default of the parameter, so a tool input has two states: `null` is "unchanged", a string is the new value.
- `RepositoryScopeSqlFilter` throws for a tenant entity without a rule. Its `$repositoryColumn()` helper reads a column of the token's repository.
- `DeletionSql` lists the tenant tables by hand, and `tenant_id` is no foreign key, so a table that it does not list survives the purge without an error.
- `contract-fixtures-type.test.ts` requires a TypeScript type for every fixture file, `contract-enums.test.ts` a TypeScript array for every schema enum, `ContractFixturesTest` a row in the fixture table of `docs/specs/README.md`, and `SchemaCoverageTest` each schema in a test literal.
- `SkillMatchesToolsTest` requires the "Tools" table of `docs/user/skill/SKILL.md` to equal `tools/list`, and `SkillFrontmatterTest` caps that file at 500 lines. `McpHandshakeTest` pins the server instructions to `docs/specs/mcp.md` section 2.
- `PullRequestRepository` locks a row with `PESSIMISTIC_WRITE` and the refresh hint in DQL.
- The MCP actor is always an agent, so no user approval, user comment, or user status change exists before the web interface.
- Claude Code cuts the server instructions at 4,096 characters and shows only the text before the cut ([MCP documentation](https://code.claude.com/docs/en/mcp.md), "Tool search for MCP server authors"). The instructions have 4,060 characters today.

## Decisions

**Model (D116).** State-stored Doctrine entities, each `TenantOwned`:

| Entity | Fields |
|---|---|
| `Project` | `id`, `tenantId`, `key` (2 to 10 upper-case letters, unique per tenant, immutable), `name`, `approvalMode`, `nextIssueNumber`, `createdAt` |
| `Repository` | new nullable `project` |
| `Issue` | `id`, `tenantId`, `project`, `number` (unique per project), `title` (at most 200 characters), `status`, `problem`, `plan`, `result`, `followUps` (Markdown, nullable, at most 65,536 characters), `planApproval` (nullable reference to the `plan_approved` entry), `createdBy`, `createdAt`, `updatedAt` |
| `IssueEntry` | Append-only: `id`, `tenantId`, `issue`, `actor`, `kind`, `body`, `data` (jsonb), `createdAt` |
| `IssueLink` | `issue`, `target`, `note`, `createdBy`, `createdAt`. Undirected: one row per pair, shown on both issues. |

- An issue is named `<key>-<number>`, for example `SM-12`. The number comes from `nextIssueNumber` under a `PESSIMISTIC_WRITE` lock on the project row. Every write locks its issue row the same way.
- Entry kinds: `comment` (body), `status_changed` (from, to, note, reviewer), `section_changed` (section, and the new text in `body`), `plan_approved` (reviewer, review note, plan revision). The plan revision is the ID of the newest `section_changed` entry of `plan`.
- A write changes the issue and appends its entry in one transaction, and sets `updatedAt`. A link sets `updatedAt` of both issues and writes no entry.

**Rules (D117).** Statuses: `draft`, `backlog`, `todo`, `in_progress`, `awaiting_user_input`, `in_review`, `done`, `wont_do`. Approval modes of a project: `user`, `agent_review`, `self`. One pure function in `src/Domain/Issue/` checks the state that a write would give:

| Status | What must hold |
|---|---|
| `backlog`, `todo`, `in_progress`, `in_review`, `done` | `problem` is not empty |
| `in_progress`, `in_review`, `done` | `plan` is not empty and approved as the mode says: `user`, by a user; `agent_review`, by a user or by an agent with a reviewer; `self`, no approval |
| `in_review`, `done` | `result` is not empty |
| `done`, `wont_do`, set by an agent | `user`: refused. `agent_review`: `reviewer` and `note` given. `self`: allowed. |
| `wont_do` | `note` given |

- The content rules hold for every actor. The approval rules bind agents only.
- An agent cannot set the status that the issue has. `todo` to `in_progress` is the claim.
- An agent changes no field and no status of an issue in `done` or `wont_do`. It can comment on it and link it.
- An agent cannot change `plan` in `in_progress` or `in_review`. A deviation goes into `result`.
- A `plan` with new text clears `planApproval`. The same text changes nothing and writes no entry.
- `approve_plan` is refused in `user` mode, for an empty plan, and for a `planRevision` that is not the newest.
- `create_issue` takes the statuses `draft`, `backlog`, and `todo`.
- A link to the issue itself or a second link of the same pair is refused.
- A refusal is `invalid_input` on the field, with the reason as its message.

**Tools (D118).** Inputs: `project` is a `ProjectKey` (`^[A-Z]{2,10}$`), `issue` and `target` are an `IssueKey` (`^[A-Z]{2,10}-[1-9][0-9]{0,8}$`), both new definitions of `common.schema.json`. Text inputs carry the `NoNulText` pattern and `minLength: 1`.

| Tool | Parameters | Does |
|---|---|---|
| `list_projects` | `cursor?`, `limit?` | Projects with `key`, `name`, `approvalMode`, and the names of their repositories |
| `list_issues` | `project`, `status?`, `cursor?`, `limit?` | Summaries with `key`, `title`, `status`, `updatedAt`, newest `updatedAt` first |
| `get_issue` | `issue` | The issue, its links, and its thread. A `section_changed` entry carries its section and no text. |
| `create_issue` | `project`, `title`, `problem?`, `status?` | A new issue, `draft` by default |
| `update_issue` | `issue`, `title?`, `problem?`, `plan?`, `result?`, `followUps?` | Changes the given fields. Refused with no field. |
| `set_issue_status` | `issue`, `status`, `note?`, `reviewer?` | Moves the issue under the rules |
| `comment_on_issue` | `issue`, `body` | A thread comment |
| `link_issues` | `issue`, `target`, `note` | A link with its reason |
| `approve_plan` | `issue`, `planRevision`, `reviewer`, `reviewNote` | An agent review of the plan. `reviewNote` has 20 characters or more. |

- The issue of every answer has: `key`, `title`, `status`, the four sections, `project` with `key`, `name`, and `approvalMode`, `planRevision`, `planApproval` (approver, time, reviewer, review note, or null), `allowedStatuses` for the caller, `createdBy`, `createdAt`, `updatedAt`. Every write answers the issue without links and thread.
- The annotations follow the existing tools: the three reads are `readOnlyHint` and `idempotentHint`.
- No REST twin yet. `docs/specs/mcp.md` sections 4 and 6 say "none yet".

**Services.** `App\Application\IssueCommands` holds the writes, `App\Application\Query\IssueQuery` the reads. Each has one consumer, `McpTools`, until the web interface adds the REST controllers. The report records this deviation from the single-consumer rule.

**Agent guidance.**
- The server instructions start with one short paragraph: when `list_projects` returns a project that owns this repository, track each request that changes code, documentation, or configuration as an issue of it, and follow the skill `slopmower-issues`; without such a project, do not use the issue tools. The `description` of `config/packages/mcp.yaml` names issues.
- The instructions stay at 4,096 characters or less, and a test pins that length. The paragraph on issues has at most 450 characters. The text on change requests gets shorter by tighter wording and keeps every rule.
- The skill `slopmower-issues` is `docs/user/skill-issues/SKILL.md`, with the same bytes in `.claude/skills/slopmower-issues/SKILL.md`. Its "Tools" table has the nine tools. The tests of the public skill also read it: tool names and parameters of both tables together equal `tools/list`, the frontmatter rules, the equal copies.
- The skill says:
  - **When to track:** a user request that changes code, documentation, or configuration and is more than a quick answer. A step of a tracked issue is a comment, not an issue. Look through the open issues of the project first. Tell the user in one line which issue was created or continued.
  - **Sections:** `problem` is what the user wants and how to tell that it is done; `plan` is the steps, the files, and the tests; `result` is what was done, with the commit SHAs, and each deviation from the plan; `followUps` is what came up and was left out.
  - **Flow:** `draft` while the problem is incomplete; `todo` when it is ready; write the plan; get it approved as `approvalMode` says, with `awaiting_user_input` and a comment that names what the user must decide; `in_progress`; `in_review` with the result; the end status as the mode allows.
  - **Agent review:** another agent or subagent reads the plan and reviews it; `reviewer` is its name, `reviewNote` its findings. The author of a plan does not review it.
  - **Commits:** the issue key goes into the commit subject.
  - **Follow-ups:** each one becomes a `draft` issue, linked to its origin with a note.
  - **Server down:** keep working, and write the entries when the server answers again.

**Fixture and dump.**
- `DevStory` creates the project `SM` "Slopmower" with `agent_review`, and sets it on the dev repository when that repository exists and has no project. A second load adds no row and keeps the mode.
- `make issues-dump` writes a `pg_dump --data-only` of `project`, `issue`, `issue_entry`, and `issue_link` of the dev database to `var/backups/issues-<UTC time>.sql`.

## In scope

- `docs/specs/`: `mcp-tools.json`, `mcp.md` sections 2 to 6, the schemas of the project, the issue, and their pages, the two key definitions, fixtures, the fixture table of `README.md`. Specs first.
- `src/Entity/`, a migration, the ID types in `config/packages/doctrine.yaml`, `src/Domain/Issue/`, `IssueCommands`, `IssueQuery`, the normalizers that the schemas need, the nine methods of `McpTools`, `BundleSchemasCommand::ROOTS`.
- `RepositoryScopeSqlFilter`: `Project` by `id IN (project_id of the token's repository)`, `Issue` by `project_id`, `IssueEntry` by `issue_id` of a visible issue, `IssueLink` by both ends visible.
- `DeletionSql`: `issue_link`, `issue_entry`, `issue`, then `project` after `repository`.
- `assets/api/types/`: the types and enum arrays that the contract tests require. No page.
- `config/services.yaml` (instructions), `config/packages/mcp.yaml` (description), the skill in both places, `DevStory`, `Makefile`.
- `docs/requirements.md`: the tracker as a functional requirement. `docs/architecture.md` section 3. The layout table and the `make` table of [README](README.md). `docs/developer-guide.md` where it lists the tools.

## Out of scope

Each one is an issue of `SM` after the merge:

- REST endpoints and pages. The first one delivers read, comment, and plan approval together.
- Create and edit a project, and the repositories of a project, outside the fixture.
- Search, an updates tool, live updates.
- MCP resources and prompts.
- A read-only token, an export, the public route of the second skill.
- `unlink_issues`, links to reviews and commits, a usage limit for issues.
- `AGENTS.md`, `docs/plan/features.md`: the orchestrator changes them after the merge (D119).

## Acceptance tests

Functional, over JSON-RPC, each answer checked against its schema:

- `create_issue`, `list_issues`, and `get_issue` return the issue, and the numbers count per project.
- Each row of the rules table refuses and allows, in each approval mode.
- A plan with new text clears the approval, and the same text keeps it.
- `approve_plan` refuses an old `planRevision`, an empty plan, and `user` mode.
- An agent cannot change the plan of an issue in `in_progress`, and cannot change an issue in `done`.
- A comment and a status change are in the thread with their actor.
- A link needs a note and is on both issues. A second link of the pair is refused.
- A token of one repository sees only the issues of its project, and no project without a repository.
- Each tool has a cross-tenant case.
- The tenant purge removes projects, issues, entries, and links.
- The dev fixture loads twice without a second project, and keeps a changed mode.

Unit: the rule function, one case per row of the table and per bullet under it.

## Verification

- `make check`.
- On the main stack after the merge: `make up`, `make fixtures`, then `list_projects`, `create_issue`, `update_issue`, `approve_plan`, `set_issue_status`, `comment_on_issue`, `link_issues`, and `get_issue` over `/_mcp` with the dev token; `make issues-dump` writes a file.
- `check-main.sh F126` after the merge.
