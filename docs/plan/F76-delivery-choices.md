# F76 Delivery choices

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-10-20:
- "The user may not want to do a pull request. When approving the plan, the user should have an option to request this work to be sent as a pull request or not."
- "The agent currently has freedom to choose the workflow: commit directly on the branch, work in a separate branch, work in a worktree. The agent should have some freedom in this, but the user should also be able to say what the agent should do." The choices go in the step that ends a set of comments, which F75 names "Submit feedback".

## Goal

- In "Submit feedback", the user picks the branch handling (agent decides, current branch, new branch, worktree) and the pull request handling (agent decides, open one, none). The review keeps the latest choice.
- At plan approval, the user decides for that one request whether its work goes into a pull request.
- The agent reads the effective choice on the request and follows it.
- Without pull requests on the host (F74), no pull request choice shows and the server refuses one.

## Facts

Verified on 2026-10-20:

- `SubmitDraftsInput` has only `summary` (`src/Controller/Api/Input/SubmitDraftsInput.php`). `ApproveProposalInput` has `proposalEventId` and `note?`, and the SPA Approve sends no note (`ProposalEntry.tsx:102`).
- **Review events:**
  - `batch.submitted` (`BatchSubmittedEvent.php`) holds `batchId`, `summary`, `requestIds`, and `commentIds`.
  - Only `ReviewProjector` and `ReviewReducer` write the review's event columns, and `app:projections:rebuild` replays them.
  - `ReviewReducer` (`:45-47`) returns the snapshot unchanged for `batch.submitted`.
  - The pattern to follow is `closeNote`: `ReviewClosedEvent.note`, then `ReviewReducer.php:58`, then `ReviewSnapshot`, then `Review::project` (`Review.php:130`) and `toSnapshot` (`:183`).
- **Proposals:**
  - `Proposal` (`src/Domain/Request/Proposal.php:17-20`) is `{eventId, state}`. It is set in `RequestReducer.php:106` and cleared on release, won't do, and reopen (`:66`, `:94`, `:100`).
  - `ChangeRequest` stores it in `proposalEventId` and `proposalState` (`ChangeRequest.php:117-120`). `ChangeRequest::project` takes `?Proposal` (`:168`).
- **Normalizers:** `ReviewNormalizer.php:33` and `RequestSummaryNormalizer.php:49` already load the repository.
- **Where the SPA reads:** the SPA renders the Submit dialog from `Review`, and the Approve button from `Request` through `threadView.ts:64-95`, on RequestDetail and FileRoute.
- **Payloads:** `PayloadReader` (`:44-61`) has no nullable bool reader and no nullable enum reader.
- **The skill today:**
  - Section 4, steps 6 to 9, says: commit, push for GitHub, link, and "Open one only when the user or the repository's instructions ask for it".
  - Section 1 says that in a worktree, the agent uses the directory name of the main repository.
- **UI fake:** `tests/ui/support/fakeReview.ts` simulates D5 (`:543`, `:645`) and Q15 (`:345`).

## Decisions

- **The schema:**
  - `Delivery {workspace: Workspace|null, pullRequest: boolean|null}`, where null means that the agent decides.
  - `Workspace` is an open set, `current_branch`, `new_branch`, `worktree`. It goes into the open list of `docs/specs/README.md:184`.
- **Properties:**
  - `Review.delivery` is the latest value of "Submit feedback". `Review.capabilities = {pullRequests}` comes from `RepositorySource::hasPullRequests()` (F74).
  - `Request.delivery` is the effective value. Its `workspace` is the review's. Its `pullRequest` is the approved proposal's value if it has one, else the review's. `Request.capabilities` is the same object as on `Review`.
  - `RequestSummary` does not change.
- **Inputs:**
  - D5 `SubmitDraftsInput.delivery?`: when absent, the review keeps its value.
  - Q15 `ApproveProposalInput.pullRequest?`.
  - `pullRequest: true` without the capability gives 422 `invalid_input`. The check is one line in `DraftController::submit` and in `RequestCommandController.php:108-115`, as R8 does it.
- **Events:** `batch.submitted` gets `delivery`, and `request.proposal_approved` gets `pullRequest`. Both are read as null when missing (README rule 8). The API `Event` does not change.
- **The proposal choice:** it is a third field of `Proposal`, with one column next to `proposalState`. It is cleared with the plan.
- **D85:**
  - Approve always sends the checkbox value, so an approval records an explicit choice.
  - A request without a plan, or from an accepted suggestion, takes its pull request choice from the review.
  - The agent reads `delivery` with `get_request` before the claim and after an approval.
- **Skill rules:**
  - `current_branch`: commit on the checked-out branch.
  - `new_branch`: one branch per review, named by the agent.
  - `worktree`: a git worktree with its own branch.
  - null: the agent's choice.
  - `pullRequest` true: push, open one, and link it.
  - `pullRequest` false: never open one.
  - `pullRequest` null: the current rule.
  - With `current_branch` on the base branch and `pullRequest` true: ask in the thread.

## Depends on

- F74 for `RepositorySource::hasPullRequests()`.
- F75 for the "Submit feedback" dialog.

## In scope

- **Specs:**
  - the schemas `common`, `review`, `request`, and `inputs`;
  - the fixtures of `Review` and `Request`;
  - V3, D5, Q15, and the Request property list in `rest-api.md` and `rest-api-requests.md`;
  - `mcp.md` section 2;
  - the 8.10 amendment entry;
  - D85 in architecture section 11.
- **Generated code:** regenerate `src/Mcp/BundledSchemas.php` (`app:schema:bundle`, `BundledSchemasUpToDateTest`).
- **Back end:**
  - `PayloadReader` readers;
  - the two events and `EventRoundTripTest`;
  - `ReviewReducer`, `ReviewSnapshot`, and `Review`;
  - `Proposal`, `RequestReducer`, and `ChangeRequest`, with one migration;
  - `ReviewNormalizer` and the `Request` normalizer;
  - the two inputs and the two controller checks;
  - a PHP enum `Workspace`, plus `EnumContractTest`.
- **SPA:**
  - `SubmitFeedbackOverlay` with "Branch" and "Pull request" selects, prefilled from `review.delivery`. The pull request select shows only with the capability.
  - The `submit` action in `reviewData.ts:61`.
  - The `ProposalEntry` Approve checkbox "Send as a pull request", only with the capability. It is checked when the effective value is true.
  - `assets/api/types`, plus a `WORKSPACES` row in `contract-enums.test.ts`.
  - `fakeReview.ts`.
- **Skill:**
  - `docs/user/skill/SKILL.md` and its byte-equal copy `.claude/skills/slopmower/SKILL.md`;
  - the MCP server instructions in `config/services.yaml`;
  - the `tools-list.json` snapshot, if a description changes.
- **User docs:** `docs/user/comment.md` (Submit feedback) and `docs/user/requests.md` (approval).

## Out of scope

- Branch names, the base branch choice, and a delivery value per request other than `pullRequest`.
- A sidebar "Delivery" line.
- Slopmower opening pull requests itself.

## Acceptance tests

- **Functional:**
  - D5 stores the delivery. An absent delivery keeps the stored value, and a projection rebuild keeps it.
  - Q15 sets `pullRequest`, and a reopen clears it.
  - `get_request` and Q3 show the effective value: the approval wins over the review.
  - A local repository gives 422 for `pullRequest: true` on D5 and on Q15.
  - An event without the new fields reads as null (`EventRoundTripTest`).
- **UI:**
  - Submit feedback sends `delivery`, and its pull request select is hidden for a local repository.
  - Approve sends `pullRequest`, and the checkbox is hidden for a local repository.
- **Journeys:** the Submit feedback dialog and the Approve captures.

## Verification

- `make check` is green.
- Manual check on a GitHub fixture review:
  - Submit feedback with "New branch, Open one", then `get_request` shows the delivery.
  - Approve with the checkbox off makes the effective `pullRequest` false.
