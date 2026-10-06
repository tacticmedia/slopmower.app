# F01 General requests

Workflow, conventions, and status: [feature track](features.md).

## Goal

A user opens a change request that belongs to the whole review and names no file, line, or symbol, for example "a full-screen focus mode" or "wrap long lines". The agent works it through the normal lifecycle: claim, reply, link commits, mark ready. The user verifies it. Decision D45.

## Decisions

- `anchor: null` marks a general request. `AnchorKind` stays unchanged.
- v1 is amended in place, not `/api/v2`: the proof of concept is unreleased, and its only clients are the web UI and the bundled skill. Specs README section 8 item 10 and architecture row D45 record it. D45 also states that rollback is impossible once a general request exists.
- Scope addition (Jan, 2026-10-07): the Requests tab lists all pending drafts of the user, not only general ones, so the reviewer sees every comment given so far without finishing the review. The data is already loaded (D1 in the review loader); only the UI is missing.

## Rules

- A general request has no capture, no location, and no excerpt. `outdated` stays null. `touchesAnchor` is false.
- It never shows in a file view. A Q1 `path` filter never returns it. `sort=path` lists general requests first.
- The lifecycle is unchanged. Drafts include general requests, so one can join a pending batch.
- Stored `request.opened` events always carry an anchor object and keep decoding.

## Depends on

M13, M25, M30. No milestone of the simplification plan blocks it.

## In scope

### 1. Specs, docs, and types

- `docs/specs/schemas/`:
  - `request.schema.json`: `anchor` is `anyOf [Anchor, null]`; when it is null, `location`, `excerpt`, and `outdated` are null.
  - `request-summary.schema.json`: `anchor` is nullable; when it is null, `outdated` is null.
  - `draft.schema.json`: the `new_request` branch accepts a null anchor.
  - `inputs.schema.json`: `OpenRequestInput` requires only `body`, and `anchor` may be absent or null. The `new_request` branch of `CreateDraftInput` no longer requires `anchor`.
  - `commit-diff.schema.json`: `touchesAnchor` is always false for a general request.
  - `anchor.schema.json`, `location.schema.json`, and `AnchorKind` stay unchanged.
- New fixtures: `request.general.json`, `inputs.open-general-request.json`, `draft.new-general-request.json`, `inputs.create-draft-general-request.json`. Register them in the specs README fixture table and in `assets/api/contract-fixtures-type.test.ts`. Story fixtures stay unchanged.
- Spec text:
  - `rest-api-requests.md`: a "General requests" paragraph in section 1, Q1 `path` and `sort=path`, Q2 body `{anchor?: AnchorInput|null, body}` with no capture errors for a general request and a second example, Q3 (`location` and `excerpt` null, `at` and `context` checked for syntax only), Q9, D2, D5.
  - `webhooks-and-live-updates.md`: recompute "each request with an anchor".
  - Specs README: section 8 item 10, section 10 anchor and request rows.
- Regenerate `src/Mcp/BundledSchemas.php` with `make console c="app:schema:bundle"`.
- `assets/api/types/`: a nullable `anchor` in `request`, `request-summary`, `draft`, and `inputs`. Null guards in `routes/reviews/fileData.ts`, `comments/draftView.ts`, and `comments/threadView.ts`. A new `requests/AnchorLink.tsx` renders "General" for a null anchor and the existing link otherwise. `RequestList.tsx` and `RequestDetail.tsx` use it. `RequestDetail` renders `AnchorSnapshot` and `LocationInfo` only with an anchor.
- Docs:
  - `requirements.md`: FR6 and FR10.
  - `architecture.md`: sections 3, 5, and 8, and row D45.
  - `developer-guide.md`: the anchor statements, and a glossary row "General request".

### 2. Domain and persistence

- `Open`, `RequestState`, and `RequestReducer` carry `?Anchor`. `RequestDecider` checks the anchor commit only for a non-null anchor.
- `RequestOpenedEvent`: `?Anchor`. `toPayload` writes `'anchor' => null`. `fromPayload` reads it with `nullableObject('anchor')`. `AnchorCodec` stays unchanged.
- `ChangeRequest.anchor` is nullable. A migration from `make migration` drops NOT NULL. `Draft::newRequest()` takes `?Anchor`. `RequestCommands::submitDrafts()` passes a null anchor through.

### 3. Queries, re-anchoring, REST, MCP, and the skill

- `RequestQuery`: no target, location, or excerpt without an anchor, so no clone is needed. `ANCHOR_PATH` is `COALESCE(JSONB_PATH_TEXT(request.anchor, 'path'), '')` for the filter, the order, and the cursor alike.
- `RecomputeLocationsHandler` skips requests without an anchor.
- `CommitDiffController`: location null and `touchesAnchor = null !== $anchorPath && ...`. Without that check, `in_array(null, ...)` matches every file with no `oldPath`.
- `OpenRequestInput` and `CreateDraftInput` drop their `NotNull` on the anchor. `RequestController::open()` and `DraftController::capture()` capture only a non-null anchor.
- `McpTools`: the `get_request` and `list_requests` descriptions. Also update `docs/specs/mcp.md` sections 4.3 and 4.5, and `tests/Fixtures/mcp/tools-list.json`.
- `docs/user/skill/SKILL.md`: how the agent handles a null anchor. It reads the body and the thread, asks with `question: true` when the scope is unclear, and lists without a `path` filter.

### 4. UI

- `comments/commentAction.ts` holds the comment intents. `fileAction` keeps `viewed` and delegates the rest. The `requests` route uses `commentAction`.
- `requests/NewRequestBox.tsx`: a "New request" button on the Requests tab of an open review. It opens the existing `CommentBox` with "Add single comment" and "Start a review" or "Add review comment", and posts Q2 or D2 with `anchor: null`. `reviewLabel(drafts)` moves to `draftView.ts`.
- A "Pending drafts" section on the Requests tab lists every pending draft of the user: new requests and replies, general and anchored. It reads the D1 data of the review loader.
  - Each draft has a label: "General", its path and lines, or the request it replies to. Its link goes to `path#Lstart-Lend` or to that request.
  - While the review is open, a draft can be edited and deleted there.
  - `DraftView` has a `label`.
- `docs/user/reviewing.md`: a new section "Open a general request".

## Out of scope

- A new `AnchorKind`, or `/api/v2`.
- Moving an existing request between general and anchored.
- An MCP tool that opens requests: agents still never open requests.

## Acceptance tests

- **U** `EventRoundTripTest`: request.opened without an anchor round-trips, with fixture `tests/Fixtures/events/request.opened-general.json`. The anchored cases still decode.
- **U** `ReducerTest`: deciding `Open(null, body)` folds to a state with a null anchor.
- **F** `OpenRequestTest`: opens a general request without a code location. The response is 201, schema-valid, and has null `anchor`, `location`, `excerpt`, and `outdated`.
- **F** `RequestListFilterTest`: a `path` filter excludes the general request, and `sort=path` lists it first.
- **MCP** `McpRestParityTest`: `get_request` on a general request equals Q3 and validates against its output schema.
- **UI** `GeneralRequest.spec.ts`: "New request" opens a general request shown as "General", and a general draft joins the batch.
- **UI** `PendingDrafts.spec.ts`: before the review is finished, the Requests tab lists a line draft, a reply draft, and a general draft with their labels. A draft's link opens its file at the drafted lines.
- Cross-tenant: no new route or tool, so the existing `api_request_open` case covers isolation.

## Verification

- `make check` is green, with `doctrine:schema:validate` and an empty `doctrine:migrations:diff`.
- Manual check:
  - On the Requests tab, open a general request. The list shows "General", the detail has no snapshot, and no file view shows the thread.
  - Over MCP, `list_requests` without a path returns the request, and `get_request` shows `anchor: null`.

## Risks

- An agent with an old copy of the skill may read `anchor.snapshot`. The live `get_request` description mitigates this. Re-install the skill after the merge.
- A client that forgets the anchor creates a general request instead of getting 422.
- There is no rollback after the first general request.
- `sort=path` and its cursor must use the same expression.
- `RequestList.tsx` stays under 800 lines.
