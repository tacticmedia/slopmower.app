# F71 Help center

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-19 for a help section that explains all the key functions of the system, and asked for help with its form. He also asked why Slopmower watches pull requests. The answer is below, and it becomes the help page `pull-requests.md`.

## Goal

- One help center answers "how do I" for every key function.
- The app links to the right page from each place where a user can get stuck.

## Facts

Verified on 2026-10-19:

- **Serving:** `DocsController` serves `/docs` and `/docs/{slug}` from `docs/user/*.md`, depth 0, as a whitelist (D28).
  - `DocsLinkRewriter` turns `<slug>.md` links into routes.
  - The firewall `docs` has no security.
- **Pages:** `index`, `getting-started`, `local-run`, `connect-your-agent`, `reviewing` (284 lines, the whole UI manual), `support`, `privacy`, `terms`.
- **Layout:** `templates/docs/layout.html.twig` has one title for every page ("Slopmower documentation"), inline light-only CSS with `max-width: 52rem`, a header link to `docs_index` only, and the legal footer.
- **App links:** the SPA links to `/docs/connect-your-agent` (Settings skill, Agent prompts) and to the legal pages from Settings. The header has no help link. `me.links.docsUrl` is not read.
- **Links into `reviewing.md`:** only `docs/user` pages, at `getting-started.md:15,46`, `connect-your-agent.md:80`, `support.md:9`, and `index.md:14`.
- **Anchors:** F65 gives the headings IDs and tests every fragment link.

### Why Slopmower watches pull requests

What a link is:
- Only an **agent** links a pull request to requests: MCP `link_pull_request`, or REST Q17 with a bearer token, when it opened a pull request for its work.
- The user never links one, and the server never opens one (F14, D50).
- A link is the event `pull_request.linked`. It changes no status and no `awaiting`.

What the server does with a link to a GitHub repository (F40, D64):
- It reads the pull request once.
- The `pull_request` webhook then keeps state, draft, merged, title, and head SHA current.
- The request sidebar and the thread show Open, Draft, Merged, or Closed, without a reload.

The check (F41):
- One check run "Slopmower (<login>)" per user, on the head commit of each open linked pull request.
- It stays in progress while a linked request is unfinished. It passes when each linked request is verified, declined by the user, or in a closed review.
- Unfinished requests on code are annotations in "Files changed": a warning while the agent works, and a notice while the request waits for the user.

What it does not do:
- It blocks a merge only when the user makes the check required in branch protection.
- **Check every pull request** (R8) gives a neutral check to pull requests without linked requests, so a required check does not block them.
- A merge or a close changes no request.

Why it exists:
- The review covers a whole codebase at one commit, but the fixes land through the normal GitHub flow. The integration closes that loop.
- The user sees which requests a pull request covers and whether it was merged. The team sees the open requests in the pull request.
- A required check stops a merge while requests are unfinished.

What it needs on GitHub:
- Pull requests: read and Checks: write, with the `pull_request` and `check_run` events.
- Slopmower writes nothing else to GitHub.

## Decisions

D79, the form: extend the public `/docs`. Do not build a help area inside the SPA.
- `/docs` exists and needs no sign-in.
- Search engines and agents can read it.
- The skill links to it.
- Each feature updates its own Markdown page.

Pages (existing slugs stay):

| Section | Page | Content |
|---|---|---|
| Start | `index.md` | Help center home: what Slopmower is, how it works, the sections |
| Start | `getting-started.md` | The four onboarding steps of F69 |
| Start | `connect-your-agent.md` | Unchanged |
| Repositories | `repositories.md` (new) | GitHub App install and access, connect, sync and Refresh, the latest commits, delete repository data |
| Reviewing | `reviewing.md` | A short overview of a review and its header, with links to the pages below |
| Reviewing | `browse-code.md` (new) | Tree, directory and README, file view, blame, wrap, viewed, Quick open, Symbols, Go to declaration |
| Reviewing | `comment.md` (new) | Comment with the mouse and the keyboard, ranges, symbols, files, general requests, title and description, drafts and "Finish your review" |
| Reviewing | `requests.md` (new) | Request list and page, statuses, talk with the agent, plan first, suggestions, linked commits, verify, reopen, decline, outdated, carry over, close a review |
| Reviewing | `pull-requests.md` (new) | The answer above, the states, the check, Check every pull request, branch protection |
| Reference | `keyboard-shortcuts.md` (new) | One table of every shortcut |
| Account | `account.md` (new) | Settings: tokens, skill, plan, usage limits, the review tour, delete repository data, delete account |
| Self-hosting | `local-run.md` | Unchanged |
| Legal | `support.md`, `privacy.md`, `terms.md` | Unchanged |

Layout:
- A left sidebar from a hand-written `templates/docs/_navigation.html.twig`, with `aria-current="page"`. Below 768 px it sits above the content.
- A header with "Slopmower Help" and "Open Slopmower" (link to `/`).
- A `<title>` per page: the first H1, then " - Slopmower Help". `DocsCatalog` reads the H1.
- "On this page" on long pages, from CommonMark `TableOfContentsExtension`.
- Dark mode through `prefers-color-scheme`, with the colours of the Primer tokens.

In the app:
- A "Help" `ActionMenu` in the `RootLayout` header (`QuestionIcon`, `aria-label="Help"`) with:
  - Help center;
  - Keyboard shortcuts;
  - Take the review tour, on review routes only. It sends A4 with `[]`, and the part of the current view starts at once;
  - Connect your agent;
  - Support.
- "Learn more" links on the onboarding steps, each Settings section, the "Check every pull request" switch, the plan state of a request, and the empty request list.
- Every help URL comes from `assets/layout/helpLinks.ts`, as constants of slug and fragment.

## Depends on

- F65: the heading IDs and the link test.
- F69: the onboarding steps and `getting-started.md`.
- F70: the tour and the Settings section.

## In scope

- `docs/user/*.md` as in the table, and the updated links into `reviewing.md`.
- `templates/docs/layout.html.twig`, `templates/docs/_navigation.html.twig`, and `src/Docs/DocsCatalog.php` (the page title).
- The `TableOfContentsExtension` registration.
- `assets/layout/RootLayout.tsx` (the Help menu), `assets/layout/helpLinks.ts`, and the "Learn more" links.
- `docs/architecture.md`: a D79 row, and the section 8 Docs row.

## Out of scope

- Search: a candidate.
- An in-app help panel, and videos.
- A full-stack journey of the docs: it is the candidate "Journeys of the Twig pages".

## Acceptance tests

- **Functional:**
  - every catalog slug is in `_navigation.html.twig`;
  - every page answers 200 with its own `<title>`;
  - every URL of `helpLinks.ts` resolves to a page and a heading ID, by an extension of the F65 link test.
- **UI** `HelpMenu.spec.ts`: the Help menu items have their hrefs, and "Take the review tour" on a review sends A4 with `[]` and starts the tour.

## Verification

- `make check` is green.
- On the dev stack: each `/docs` page at 1280 px and 390 px, in light and dark mode, and the Help menu.
