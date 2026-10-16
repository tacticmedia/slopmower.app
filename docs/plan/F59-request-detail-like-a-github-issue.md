# F59 Request detail like a GitHub issue

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-16:

1. The action buttons of the Request Detail (resolve conversation, reopen) must stay at the top when the page scrolls.
2. The labels are confusing. The page says "Ready for review", "Waiting for you", and "Plan required" while the agent says the work on an approved plan is done. The labels belong to different stages, and the page does not show that.

## Goal

- The primary actions stay visible at the top of the window on a long thread.
- The header says one thing about the state: the status, and one sentence that tells the user what happens next.
- The plan requirement shows the value that matters in the current stage. After the work is done it never says "required".
- The page has the GitHub issue layout: title block, conversation on the left, metadata on the right.

## Facts

Verified on 2026-10-16:

- `assets/routes/requests/RequestDetail.tsx` (134 lines) is an `<article data-testid="request-detail">` under `RootLayout`, outside `ReviewLayout`, styled by `RequestDetail.module.css` (`.page` flex column, max-width 1012 px). Its `<header>` holds the h1 `summary`, a `.meta` row (StatusLabel, OutdatedLabel, TurnLabel, SuggestionLabel, the "Plan required" Label from `requireApproval`, AnchorLink, the symbol FQN, "Opened by"), `LocationInfo`, "Claimed by", the pull request list, `RequestActions`, and `SuggestionActions`. Then the closed-review banner, the `UnderlineNav` Conversation | Changes, and the tab content.
- `RequestActions` (`assets/requests/RequestActions.tsx`) shows, in the order of `REQUEST_BUTTONS` in `allowedActions.ts`: Resolve conversation (`verify`), Reopen, Ask for a plan first / Don't ask for a plan (`set_plan_requirement`), Close as won't do, Unassign. `SuggestionActions` shows Accept (with "Ask for a plan first") and Dismiss. Approve / Request changes live under the proposal in the thread (`ProposalEntry.tsx`).
- `requireApproval` is a setting. It stays true after the plan is approved and after `ready_for_review`. `proposal` (`{eventId, state}`) stays after `mark_ready_for_review`; release, won't do, and reopen set it to null. So "Plan required" shows on a finished request (architecture section 6).
- `awaiting` rules are in architecture section 6. `closed` equals `awaiting === 'none'`.
- The app header is not sticky. `assets/review/FileHeader.tsx` has `useStuck()` (lines 100 to 115): an `IntersectionObserver` on a 1 px sentinel.
- Tests that read this page: UI `PlanRequirement`, `Proposal`, `ProposalInFileView`, `Reopen`, `Unassign`, `WontDo`, `ClosedRequestReadOnly` (no button outside the tab `nav` on a closed review), `RequestAnchor`, `OutdatedLabel`, `PullRequestLink`, `Suggestion`, `ChangesTab`, `CarryOver`, and the helper `tests/ui/support/requestScreens.ts` (`DETAIL_STATUS` and `DETAIL_TURN` read `header [data-testid=...]`); E2E `VerticalSliceTest`, `ProposalFlowTest`, `SuggestionFlowTest`, `LiveUpdateTest`, and `tests/Support/E2E/RequestScreens.php`.

## Decisions

- **Layout:**
  - `<header>`: the h1 (with the title edit of F58), the link "Requests of this review", and one state line: `StatusLabel` and "{author} opened this {relative time} · {n} comments".
  - Below it, a direct child of the article: the **action bar**, `position: sticky; top: 0`, with the page background, a bottom border, and a z-index above the thread. It holds `RequestActions` without the plan toggle, and `SuggestionActions`. While stuck, it also shows a compact `StatusLabel` (`data-testid="stuck-status"`) and the summary, truncated to one line. The bar renders nothing on a closed review, and is not rendered when it would hold no button.
  - Then the next-step line, the closed-review banner, and the tabs.
  - Then a CSS grid: the main column (`AnchorSnapshot`, the thread, the reply box; or the Changes tab) and a sidebar of 256 px from 1012 px viewport width. Below that width the sidebar follows the header, before the tabs.
- **`useStuck()`** moves to `assets/layout/useStuck.ts`. `FileHeader` and `RequestDetail` use it.
- **Next-step line** (`data-testid="next-step"`, `data-awaiting="<awaiting>"`, the attention colour when `awaiting === 'user'`, else muted): one sentence from the pure function `assets/requests/requestStage.ts`. It replaces `TurnLabel` and the "Plan required" label on this page. The first matching rule wins:

  | # | Condition | Sentence |
  |---|---|---|
  | 1 | `suggestion === 'pending'` | "The agent suggested this change. Accept it so that an agent can work on it, or dismiss it." |
  | 2 | `status === 'verified'` | "Resolved." |
  | 3 | `wont_do`, closed | "Closed as won't do." |
  | 4 | `ready_for_review` or `wont_do`, and `awaiting === 'agent'` | "Waiting for the agent to answer your reply." |
  | 5 | `awaiting === 'user'` and the last agent thread entry is a reply with `question: true` | "The agent asked a question. Reply to continue." |
  | 6 | `proposal.state === 'pending'` | "The agent proposed a plan. Approve it or request changes." |
  | 7 | `ready_for_review` | "The agent finished. Check the changes, then resolve the conversation or reopen it." |
  | 8 | `wont_do` (set by an agent) | "The agent closed this as won't do. Resolve the conversation to agree, or reopen it." |
  | 9 | `in_progress`, `requireApproval`, `proposal.state === 'rejected'` | "You requested changes to the plan. The agent revises it." |
  | 10 | `in_progress`, `requireApproval`, `proposal === null` | "The agent prepares a plan for your approval before it changes code." |
  | 11 | `in_progress` | "{claimedBy.name} is working on this." |
  | 12 | `open` or `reopened` | "Waiting for an agent to claim this." When `requireApproval`, add "It proposes a plan first." |

- **Sidebar sections**, each a small heading and a value:
  - **Assignee:** "{agent} for {age}" (`ClaimedBy`) while `in_progress`, else "No one".
  - **Plan** (`data-testid="plan-state"`): while `open`, `reopened`, or `in_progress`: "Waiting for your approval" (pending), "Approved", "Changes requested" (rejected), "Required before code changes" (`requireApproval` without a proposal), or "Not required". After the work (`ready_for_review`, `verified`, `wont_do`): the state of the proposal when there is one, else "No plan". The toggle "Ask for a plan first" / "Don't ask for a plan" is a small button in this section when `set_plan_requirement` is allowed.
  - **Location:** `AnchorLink` or "General", the symbol FQN, `LocationInfo`, and `OutdatedLabel`.
  - **Development:** the linked pull requests with `PullRequestStateLabel`, URL, and title; "{n} linked commits", a link to the Changes tab; "None yet" when both are empty.
  - **Suggested by** the agent name, when `suggestion` is not null.
- The list, the code-view thread header, and `FileHeader` keep their labels. F60 changes the list.

## Depends on

F58 (title in the header and the title edit), F57 (journeys).

## In scope

- `assets/routes/requests/RequestDetail.tsx` and `RequestDetail.module.css`, split into small components under `assets/requests/` where the file would pass about 200 lines (for example `RequestSidebar.tsx`, `ActionBar.tsx`); `assets/requests/requestStage.ts` and its Vitest file; `assets/requests/RequestActions.tsx` and `allowedActions.ts` (the plan toggle moves out of the bar); `assets/layout/useStuck.ts`; `assets/review/FileHeader.tsx` (uses the shared hook).
- Tests: the specs and helpers listed in Facts that this layout breaks, the E2E selectors, and the F57 journey `RequestStages.spec.ts`.
- Docs: `docs/user/reviewing.md` (the request page, the next-step line, the plan states), the `architecture.md` "Request detail" screen row.

## Out of scope

- The request list and the review header (F60).
- Request numbers (candidate).

## Acceptance tests

- **Vitest** `requestStage.test.ts`: one case per rule of the table, and rule 12 with and without `requireApproval`.
- **UI** `RequestDetailLayout.spec.ts`:
  - With a thread of 30 replies at 1280x720, after a scroll to the bottom, the action bar is inside the viewport, shows the compact status, and "Resolve conversation" sends Q12.
  - A `ready_for_review` request with `requireApproval: true` and an approved proposal shows "Approved" in the Plan section and no text "Required" on the page.
  - The next-step line for a pending suggestion, a pending proposal, ready for review, and an agent question, each with its `data-awaiting`.
  - The plan toggle in the sidebar sends Q18.
  - At 390 px width the sidebar follows the header and the page has no horizontal overflow.
- `ClosedRequestReadOnly.spec.ts` stays green: a closed review shows no button outside the tab `nav`.

## Verification

- `make check` is green.
- `make journeys`: the request stages at 390 px and at 1280 px show the bar, the next-step line, and the sidebar.
