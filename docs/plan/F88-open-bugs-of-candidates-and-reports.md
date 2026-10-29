# F88 Open bugs of the Candidates and reports

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-10-29: "keep going", after F84 to F87 closed the audit of 2026-10-28. The rule "bugs and issues before features" puts the open bugs of the Candidates and of the report follow-ups ahead of the [declarative model](declarative-model.md).

## Goal

A review without a ref pins the head of the default branch, also when a tag has the name of the branch. The app works at 320 px. Every table keeps its frame on a phone. The cookie notice follows the colour mode. A review without requests says so, and a cut check run headline keeps its code span closed.

## Facts

Verified on 2026-10-29 on `87c16d3`:

- **V1 default branch.** `ReviewCommands::defaultBranch()` gives the bare branch name, and `GitReader::resolveCommit()` resolves it with `git rev-parse`, where a tag of the same name wins. R9 and `ServerClone` resolve `refs/heads/<name>`. The web form fills **Ref** with the default branch and sends it, so it has the same defect. Candidate "V1 default-branch resolution".
- **Header at 320 px.** The header items need 406 px. The header scrolls inside itself (`overflow: auto`), so "Sign out" is cut at 390 px and hidden at 320 px, the width of WCAG 2.2 success criterion 1.4.10 (Reflow). The F71 report saw the start of this.
- **Table frames.** Only the review table and the token table use `assets/layout/ScrollingTable.module.css`. The usage table, the repository data table, the connected repositories, and both candidate tables lose their right edge on a phone. Candidate "Right edge of the other tables on a phone", F64 report.
- **Cookie notice.** `templates/base.html.twig` styles the notice with light colours. `_theme.html.twig` adds the dark colours, but only the sign-in page and the docs include it, so the notice of the SPA stays light in dark mode. Candidate "Cookie notice in dark mode", F71 report.
- **Empty request list.** `RequestList` says "No request matches these filters. Clear the filters" also for a review without any request. F71 report.
- **Check run headline.** `CheckRunOutput::headline()` cuts at 80 characters and can cut inside a code span. The summary puts the headline into Markdown link text, so GitHub shows a stray backtick. F64 report.

## Decisions

- **V1 default branch.** Without `ref`, and with a `ref` that is the name of the default branch, V1 resolves `refs/heads/<default branch>`. The review keeps the bare name as its `ref` and in its default title. Any other `ref` keeps the `git rev-parse` order.
- **Header.** Below the `sm` breakpoint of Primer (544 px), the header hides the "Slopmower" text for screen readers only and the avatar, and has 8 px of inline padding.
- **Table frames.** Every `Table.Container` uses `ScrollingTable.module.css`.
- **Cookie notice.** `base.html.twig` holds the notice styles for both colour modes, and `_theme.html.twig` holds none.
- **Empty request list.** A review with `counts.total` 0 says "No requests yet." with the help link, and no filter link.
- **Headline.** A cut that leaves an odd number of backticks closes the code span before the ellipsis.

## Depends on

None.

## In scope

`src/Application/ReviewCommands.php`, `assets/layout/RootLayout.tsx` and its CSS module, the five other `Table.Container` sites, `templates/base.html.twig`, `templates/_theme.html.twig`, `assets/routes/requests/RequestList.tsx`, `src/Domain/Request/CheckRunOutput.php`, and their tests.

## Out of scope

- A user menu in the header.
- The other Candidates, which are features or limitations.

## Acceptance tests

- Functional: V1 without `ref`, and with the name of the default branch, pins the head of the branch while a tag of the same name points at another commit.
- UI: at 320, 360, and 390 px the header does not scroll, and "Sign out" is inside the window.
- UI: at 390 px each table of Settings and the repositories page draws its right edge inside the window.
- E2E: in dark mode the notice of an SPA page has the dark muted background.
- UI: a review without requests says "No requests yet." and shows no filter link.
- Unit: a headline cut inside a code span ends with a closing backtick and the ellipsis.

## Verification

`make cs`, `make lint`, `make stan`, `make test`, `make front`, `make ui`, and `make e2e`. A mutation check per fix.
