# F98 Comment groups

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "I want to be able to make certain comments to relate to each other - when I want to comment on multiple lines, but want to treat that comment as a single unit of feedback. I think the best way is to allow grouping by a free-text input field, where all comments with the same value belong to the same comment group."

## Goal

The user gives comments the same group label. The agent works on the requests of a group as one unit of feedback: one change, one commit.

## Facts

Verified on 2026-11-03:

- A request has exactly one anchor (`RequestState::$anchor`). The location cache, the outdated flag, carry-over, the check-run annotations, the commit diff, the path filter and sort of Q1, and the thread placement of the file view all assume one anchor.
- New request fields go into the jsonb `state` (D98). `Draft` has real columns.
- D3 replaces `title`: an absent title clears it (`tests/ui/support/fakeReview.ts:572-588`, `UpdateDraftInput`).
- Q1 and `list_requests` filter by `status`, `awaiting`, `path`, `outdated`, and `suggestion`.
- The caller's drafts of a review are all loaded (D1), in the code view and on the request list.

## Decisions

Jan chose the group label on separate requests on 2026-11-03, over one request with several anchors.

- D102: `group` is a string of 1 to 80 characters after trim, without a line break, or null.
  - `Draft.group` is a column. D2 takes `group`. D3 replaces it as it replaces `title`.
  - Q2 takes `group`. Carry-over copies the group.
  - `request.opened` carries `group`; an event without it reads as null. Request, RequestSummary, and Draft show `group`.
  - Q1 and `list_requests` take the filter `group`, an exact match.
  - The group does not change after the request opens.
- Each request of a group keeps its own anchor, thread, status, and verification.
- The agent rule: requests with the same `group` are one unit. Read them with `list_requests` and `group`, claim them, make one change in one commit linked to every ID, and mark each ready with the same note. With `requireApproval`, propose the same plan on each.
- UI: an optional "Group" field in the comment box of a new request, with a `datalist` of the group labels of the caller's drafts, so that a typo does not split a group. Drafts, request rows, and the request detail sidebar show the group. On a row and in the sidebar, the group links to the request list filtered by `?group=`.

## Depends on

F97, which changes the same inputs, events, and components.

## In scope

- **Specs:** the `request`, `request-summary`, `draft`, `inputs`, and `lists` schemas; fixtures; D2, D3, Q1, Q2, Q3; `mcp-tools.json` (`list_requests`, `get_request`); `mcp.md`; the amendments of `docs/specs/README.md`; D102.
- **Generated:** `src/Mcp/BundledSchemas.php`.
- **Back end:** `Draft` with a migration, the inputs, `Open`, `RequestDecider`, `RequestOpenedEvent`, `RequestState`, `RequestReducer`, `CarryOverController`, `RequestQuery` (filter), `McpTools` (`list_requests`), the normalizers.
- **SPA:** `assets/api/types`, `CommentBox`, `DraftBox`, `RequestRow`, the request detail sidebar, the request list query, `fakeReview.ts`.
- **Skill:** both copies, and `config/services.yaml`.
- **User docs:** `docs/user/comment.md` and `docs/user/requests.md`.

## Out of scope

- One request with several anchors.
- A group change after the request opens.
- Verification or approval of a whole group at once.
- Suggestions from the groups of submitted requests.

## Acceptance tests

- Functional: D2 with `group`; D3 replaces it; a blank group or one with a line break gives 422; D5 carries it to Q3 and `get_request`; Q1 and `list_requests` with `group` return only that group.
- Unit: `EventRoundTripTest` reads a `request.opened` without `group` as null.
- UI: a suggestion of the datalist fills the field; the group goes into the D2 body; the group label of a request row opens the filtered list.

## Verification

- `make check` is green.
- `cmp docs/user/skill/SKILL.md .claude/skills/slopmower/SKILL.md` is silent, and `BundledSchemasUpToDateTest` passes.
