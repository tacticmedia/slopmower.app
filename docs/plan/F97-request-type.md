# F97 Request type: change, question, rule

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "I want to be able to tell the agent that I want it to follow a specific rule from now on. For instance, this would be used to steer claude into specific coding style, banning certain coding techniques etc. - these rules should go to a project-specific memory file so that the agent would always know this. [...] I want to be able to change the type of the comment while it's a draft."

## Goal

- A new request has a type: Change, Question, or Rule. The user sets it in the comment box and can change it while the request is a draft.
- The agent answers a question without a code change, and stores a rule in `AGENTS.md` and follows it.

## Facts

Verified on 2026-11-03:

- A request has no type field. `RequestState` holds `anchor`, `title`, `requireApproval`, `suggestion`, `carriedFrom`, `proposal` (`RequestState.php:34-55`), stored as jsonb (D98), so a new field needs no migration of `change_request`.
- `Draft` has real columns (`src/Entity/Draft.php:25-64`). `Draft.kind` (`new_request|reply`) is fixed at construction. D3 (`UpdateDraftInput`) changes `title`, `body`, and `requireApproval`; a null `requireApproval` keeps the value, and a reply refuses it (`Draft.php:107-121`).
- `requireApproval` is the precedent of a per-request field. Its path: `CreateDraftInput`, `UpdateDraftInput`, `OpenRequestInput`, `Open`, `RequestOpenedEvent` (read with a default at `:61`), `RequestState`, the normalizers, the schemas `request`, `request-summary`, `draft`, `inputs`, the fixtures, `BundledSchemas.php`, `assets/api/types`, `CommentBox`, `DraftBox`.
- Open sets are listed in `docs/specs/README.md:186`. `EnumContractTest` and `assets/api/contract-enums.test.ts` pin the values.
- Claude Code reads `AGENTS.md` only when the directory has no `CLAUDE.md`, or when `CLAUDE.md` imports it (Claude Code memory docs, setting "Project instructions", default `claude-md-or-agents-md`).
- The skill and the MCP server instructions name no repository instruction file today.

## Decisions

Jan chose the three types and `AGENTS.md` on 2026-11-03.

- D101: `RequestType` is an open set: `change` (default), `question`, `rule`.
  - `Draft.type` is a column. D2 takes `type`, default `change`. D3 takes `type`, and null keeps the value. A reply draft refuses a type.
  - Q2 takes `type`. Carry-over copies the type.
  - `request.opened` carries `type`; an event without it reads as `change`. Request, RequestSummary, and Draft show `type`.
  - The type does not change after the request opens.
- The agent rules, in the skill, the MCP server instructions, and the `get_request` description:
  - `change`: as today.
  - `question`: claim, answer with `reply_to_request`, change no code, then `mark_ready_for_review` with a note that points to the answer. A needed change is named in the answer, not made.
  - `rule`: claim, then add the rule as one bullet under `## Rules from Slopmower` in `AGENTS.md` at the repository root, in the user's words. Create the file when it is missing. When `CLAUDE.md` exists and does not import `AGENTS.md`, add `@AGENTS.md` to it in the same commit. When the anchored code breaks the rule, fix it in the same commit. Follow the rule from then on. Commit, link, mark ready.
- UI: a "Type" select (Change, Question, Rule) wherever the comment box shows "Ask for a plan first". Question hides the checkbox and sends `requireApproval` false. A label shows Question and Rule on drafts, request rows, and the request detail.

## Depends on

F96, which changes the draft inputs and `DraftBox`.

## In scope

- **Specs:** `common`, `request`, `request-summary`, `draft`, and `inputs` schemas; fixtures; D2, D3, Q2, Q3 in `rest-api-requests.md`; `mcp.md`; `mcp-tools.json`; the open sets and the amendments of `docs/specs/README.md`; D101.
- **Generated:** `src/Mcp/BundledSchemas.php` (`app:schema:bundle`).
- **Back end:** a PHP enum `RequestType`, `Draft` with a migration (`make migration`), the inputs, `Open`, `RequestDecider`, `RequestOpenedEvent`, `RequestState`, `RequestReducer`, `CarryOverController`, the normalizers, `EnumContractTest`.
- **SPA:** `assets/api/types` and `REQUEST_TYPES` in `contract-enums.test.ts`, `CommentBox`, `DraftBox`, `draftView.ts`, `RequestRow`, the request detail header, `fakeReview.ts`.
- **Skill:** `docs/user/skill/SKILL.md` and its byte-equal copy `.claude/skills/slopmower/SKILL.md`; `config/services.yaml`.
- **User docs:** `docs/user/comment.md` and `docs/user/requests.md`.

## Out of scope

- A type change after the request opens.
- A type filter on the request list.
- Rules stored by Slopmower itself.

## Acceptance tests

- Functional: D2 with and without `type`; D3 changes the type, and null keeps it; a reply draft refuses a type; D5 carries the type to Q3 and to `get_request`; Q2 with `type`.
- Unit: `EventRoundTripTest` reads a `request.opened` without `type` as `change`; the `RequestType` row of `EnumContractTest`.
- UI: the Type select goes into the D2 and D3 bodies; Question hides "Ask for a plan first"; the labels show on a draft and a request row.

## Verification

- `make check` is green.
- `cmp docs/user/skill/SKILL.md .claude/skills/slopmower/SKILL.md` is silent, and `BundledSchemasUpToDateTest` passes.
- Mutation check: without the default for an old event, the round-trip test fails.
