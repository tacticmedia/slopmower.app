# F58 Request title and description

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-16: a request has a title and a description. The title is optional. When there is no title, the agent is told to write one from the description. Headings, lists, and browser titles show the title, so a request reads like a GitHub issue.

## Goal

- A user can give a request a title when it opens, and can set or change the title later.
- An agent sets the title of an untitled request from its description, and always gives a title to a suggestion.
- Every heading, list row, label, and browser title shows the title, or the first line of the description while there is none.

## Facts

Verified on 2026-10-16:

- The request text is `body` everywhere: `ChangeRequest::$body` (TEXT), the `request.opened` payload, `RequestState::$body`, the inputs `OpenRequestInput` (Q2), `SuggestRequestInput` (Q20), `CreateDraftInput` (D2), `UpdateDraftInput` (D3), `Draft::$body`, `Request.body`, and the MCP `suggest_request` parameter `body`. Carry-over (V15, `CarryOverController`) copies the body. No command edits it.
- `summary` (`Request`, `RequestSummary`, 1 to 200 characters) comes from `RequestSummaryNormalizer::summary()`: the first non-blank line of the body, trimmed, cut with U+2026. The Request Detail h1, the request list, the draft labels "Reply to <summary>", and the carry-over panel show it.
- The check run headline is `CheckRunOutput::headline()`: the first line with text, cut to 80 characters with "...". It is the annotation `title` and the link text of the run summary. `docs/specs/webhooks-and-live-updates.md` and `docs/user/privacy.md` describe it.
- The review title precedent: `StartReviewInput::$title` is `?string` with `#[Assert\Length(min: 1, max: 200)]`, and `review.schema.json` has `title` 1 to 200.
- Stored events are never rewritten. A new payload field is additive and read with a default (`PayloadReader::nullableString()`), as `requireApproval` was.
- 23 event types, 20 thread entry types, 17 `Action` cases, 17 MCP tools. The last endpoint is Q22, the last MCP section 4.17, the last decision D73, and F50 holds D74.
- No route sets `document.title`. `templates/base.html.twig` sets "Slopmower".
- `docs/developer-guide.md` has the checklist "A new field on a request".

## Decisions

D75 in [architecture section 11](../architecture.md#11-decisions).

- **Names:** the description keeps the wire and entity name `body`, as in GitHub's issue API (`title` and `body`). The UI calls it "Description". A rename would need a second payload key for the stored events and changes every client for no gain.
- **`title`:** `string|null`, 1 to 200 characters, one line (no CR or LF), trimmed, and not whitespace only. A new property of `Request`, `RequestSummary`, and `Draft`, null when not set.
- **Inputs:**
  - Optional `title` in `OpenRequestInput` (Q2) and `CreateDraftInput` (D2) for a new-request draft. A reply draft with a title gets 422 `invalid_input`.
  - `UpdateDraftInput` (D3) replaces `body` and `title` together: an absent title clears it.
  - D5 submit opens each new-request draft with its title.
  - **Required** `title` in `SuggestRequestInput` (Q20) and in the MCP tool `suggest_request`: the agent writes the suggestion, so it always gives one. A new required input is a pre-release amendment.
  - V15 copies the title of the source request.
- **`summary`:** the title when set, else the first non-blank line of the body as now. The check run headline follows the same rule, cut to 80 characters. A changed meaning is a pre-release amendment.
- **Events:**
  - `request.opened` gets the payload field `title`, read as null when missing, appended after `requireApproval`.
  - New event type `request.retitled {title}`, also a thread entry type. Its thread entry `body` is the new title. UI caption "set the title".
  - `awaiting`: a retitle is no user message for rule 2 and no agent event for rules 2 and 3, as `request.plan_required` and `pull_request.linked` are, so it never moves the turn.
  - The check run trigger list of `webhooks-and-live-updates.md` gets `request.retitled`, because the headline changes.
- **Command `set_title`:**
  - New `Action` value `set_title`, command `SetTitle {title}`, endpoint **Q23** `POST /api/v1/requests/{requestId}/title`, authentication S and B, body `TitleInput {title}` (required, same rules), success 200 `RequestCommandResult` with one `request.retitled` event, or `events: []` when the title does not change.
  - The user may set the title in every status while the review is open. A title cannot be removed.
  - An agent may set it only while `title` is null. Otherwise 409 `invalid_transition`, and `allowedActions` of the agent lacks `set_title`.
  - Errors: 409 `review_closed`, 409 `invalid_transition` (agent, title set), 422 `invalid_input`, 404.
  - Rate limits as the other request commands. A cross-tenant case.
- **MCP tool 4.18 `set_request_title(id, title)`:** output `RequestCommandResult`. `suggest_request` becomes `(review, title, body, anchorKind?, path?, startLine?, endLine?, symbol?)`.
- **MCP instructions** (`mcp.md` section 2 and `app.mcp_instructions`, equal text) and both skills (`docs/user/skill/SKILL.md`, `.claude/skills/slopmower/SKILL.md`): "A request with `title` null has no title. Before you claim it, call set_request_title with one line of at most 80 characters that summarizes its body." The suggest step says to give each suggestion a title of at most 80 characters.
- **Contract:** v1 is amended in place before the first release. Specs README section 8 item 10 gets the D75 note: the property `title` of `Request`, `RequestSummary`, and `Draft`; the optional input `title` of Q2, D2, and D3; the required `title` of Q20 and `suggest_request`; the new meaning of `summary`; Q23, the tool `set_request_title`, the `Action` value `set_title`, the `EventType` and `ThreadEntryType` value `request.retitled`, and `TitleInput`. Section 2.2 gets the title limits.
- **Rollback:** none after the first `request.retitled` event, because an older build cannot decode it.
- **UI:**
  - `CommentBox`, when it opens a request (`NewRequestBox`, the file-view composer, a new-request draft), gets an optional single-line "Title" field above the textarea, and the textarea label becomes "Description". It sends `title` only when the field is not blank, so a request without a title sends the same body as now. A reply has no title field.
  - Request Detail: the h1 shows `summary`. With `set_title` in `allowedActions`, an "Edit" button turns the h1 into a text input with "Save" and "Cancel" (Enter saves, Escape cancels). An untitled request shows its h1 muted and the button "Add a title".
  - The code-view thread header shows `summary` above the status labels.
  - `assets/layout/useDocumentTitle.ts` sets `document.title` to `"<summary> · Slopmower"` on the Request Detail and `"<review title> · Slopmower"` on the review pages, and restores "Slopmower" on unmount.
  - `DraftBox` edits the title of a new-request draft.

## Depends on

F57, so the journeys capture the title UI.

## In scope

Build in three parts, one WIP commit each, squashed at the end.

1. **Specs and docs, before code:**
   - `docs/specs/schemas/`: `common.schema.json` (`Action`, `EventType`, `ThreadEntryType`), `request.schema.json` and `request-summary.schema.json` (`title`, `summary` description), `draft.schema.json` (`title`), `inputs.schema.json` (`title` in `OpenRequestInput`, `SuggestRequestInput`, `CreateDraftInput`, `UpdateDraftInput`; new `TitleInput`), `event.schema.json` and `thread-entry.schema.json` (`body` description).
   - Fixtures: `"title"` in every `Request`, `RequestSummary`, and `Draft` fixture (one titled example each where it reads well, null elsewhere); `inputs.suggest-request.json` with a title; new `inputs.title.json`, `request-command-result.retitled.json`, `tests/Fixtures/events/request.retitled.json`, and a titled `request.opened` event fixture. Register new fixtures in specs README section 11 and in `assets/api/contract-fixtures-type.test.ts`.
   - `rest-api.md` section 2 (Q23 row) and V15; `rest-api-requests.md` section 1 (command rows, `allowedActions` row `set_title`), Q1 and Q3 text where `summary` is described, Q2, Q20, Q23, D2, D3, D5.
   - `mcp.md`: section 2 instructions, 4.3 and 4.5 descriptions, 4.17, new 4.18, the tables of sections 4 to 6.
   - `webhooks-and-live-updates.md`: the headline rule and the check run triggers.
   - Specs `README.md`: section 2.2, section 8 item 10, sections 10 and 11.
   - `architecture.md`: section 3 (`ChangeRequest.title`, `Draft.title`, the `request.opened` row and example, the `request.retitled` row), section 6 (inputs of `open` and `suggest`, a `set_title` row, the `awaiting` note), section 7.3 tool table, the "Request detail" and "Requests" screen rows, D75.
   - `docs/user/reviewing.md` (title and description, edit the title), `docs/user/privacy.md` (headline), `docs/user/skill/SKILL.md`, `.claude/skills/slopmower/SKILL.md`, developer guide glossary and the checklist "A new field on a request" if it lacks a step.
2. **Back end:**
   - Domain: `RequestState::$title`, `RequestReducer` (`opened()`, `retitled()`, `tracking()` ignores a retitle), `RequestDecider` (title rules for `Open`, `Suggest`, `SetTitle`; the agent rule), `AllowedActions` (`set_title`), `Action::SetTitle`, commands `Open` and `Suggest` (trailing `?string $title`; `Suggest` requires it at the input boundary), new `Command\SetTitle`, `RequestOpenedEvent` (`title`), new `RequestRetitledEvent` with its `EventType` case, codec, and `RecordedEvent::requestIds()`.
   - Persistence: `ChangeRequest::$title` and `Draft::$title`, `VARCHAR(200)` nullable, one migration through `make migration`; `RequestProjector`, `RequestStateMapper`.
   - Application and HTTP: `RequestCommands::setTitle()`; Q23 in `RequestCommandController`; `TitleInput`; `title` in `OpenRequestInput`, `SuggestRequestInput`, `CreateDraftInput`, `UpdateDraftInput`; `DraftController` create, update, and submit; `CarryOverController` copies the title.
   - Output: `RequestSummaryNormalizer` (`title`, `summary` rule), `RequestNormalizer`, `EventNormalizer` (`request.retitled` body), `DraftNormalizer`; `CheckRunOutput::headline()` takes the summary rule; the check run trigger for `request.retitled`.
   - MCP: `McpTools::setRequestTitle()`, `suggestRequest()` with `title`, `app.mcp_instructions`, `app:schema:bundle` for `BundledSchemas.php`, `tests/Fixtures/mcp/tools-list.json`.
3. **UI:** types in `assets/api/types/`; `CommentBox`, `NewRequestBox`, `FileRoute` composer, `useFileComments`, `commentIntent`, `commentAction`, `DraftBox`; `RequestDetail` title edit through `requestIntent` / `requestAction`; `ThreadBox` header and the caption "set the title"; `threadView`; `useDocumentTitle` on `RequestDetail` and `ReviewLayout`; `tests/ui/support/fakeReview.ts` and `builders.ts` (`summaryText()` follows the title rule; a fake Q23); the F57 journeys of the changed screens (`RequestStages`, `ReviewRequests`, `CodeBrowser`).

## Out of scope

- Editing the description. The user replies in the thread.
- Request numbers per review (GitHub `#N`). Candidate.
- Search or a filter by title.

## Acceptance tests

- **Unit:**
  - `RequestDecider`: a title of 201 characters, with a line break, or of whitespace is refused; `Suggest` without a title is refused; `SetTitle` by an agent on a titled request throws `InvalidTransition`; the same title writes no event.
  - `AllowedActionsTest`: `set_title` for the user in every status of an open review; for an agent only while the title is null; none on a closed review.
  - `AwaitingPolicyTest` or `ReducerTest`: a retitle by either actor keeps `awaiting`.
  - `EventRoundTripTest`: `request.retitled`, a titled `request.opened`, and an old `request.opened` without `title` that reads null.
  - `CheckRunOutputTest`: the headline is the title when set.
- **Functional:**
  - Q2 with and without a title; Q20 without a title gives 422; D2 with a title on a new-request draft and on a reply draft (422); D3 replaces the title; D5 opens with the title.
  - Q23: the user retitles a verified request; the agent sets an untitled one, then gets 409 on the second call; a closed review gives 409 `review_closed`; Q23 in `CrossTenantCases`.
  - The summary rule: a titled request has `summary` equal to its title.
  - `CarryOverTest`: the carried request keeps the title.
  - `CheckRunJobTest`: the annotation title is the request title.
  - A projection rebuild keeps the title (`ProjectionConsistencyTest`).
  - MCP: `set_request_title` and `suggest_request` with a title in `McpRestParityTest`; `ToolsListContractTest` (18 tools); `McpCrossTenantCases`; `SkillMatchesToolsTest`.
  - Every response validates against the schemas.
- **UI:**
  - `GeneralRequest.spec.ts`: a new request with a title sends it, and the list row shows it.
  - `LineComment.spec.ts`: a line comment without a title sends no `title` key.
  - A new spec `RequestTitle.spec.ts`: Edit, Save sends Q23 and the h1 shows the new title; Escape cancels; an untitled request shows "Add a title"; `document.title` is `"<title> · Slopmower"`; a closed review shows no Edit button.
- **E2E:** `SuggestionFlowTest` sends a title, and the browser list shows it.

## Verification

- `make check` is green, with `doctrine:schema:validate` and an empty `doctrine:migrations:diff`.
- Over MCP on the worktree stack: `suggest_request` without `title` answers `invalid_input`; `set_request_title` on an untitled request succeeds once and the second call answers `invalid_transition`.
- `make journeys` captures the titled and untitled request detail and the list.
