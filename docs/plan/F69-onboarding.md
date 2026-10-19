# F69 Onboarding

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-19: while no account or repository is connected, show a world-class onboarding screen. It names the step to take: connect a repository, pick a point to review, start a review. Jan agreed to a fourth step, "Connect your agent".

## Goal

From the first sign-in to the first request that an agent can pick up, the user always sees the next step.

## Facts

Verified on 2026-10-19:

- **`/`:** `assets/routes/HomeRoute.tsx` is an h1 and one sentence: "Go to Repositories to connect a repository and start a review."
- **`/repositories`:** `RepositoriesRoute.tsx` loads R1 (all pages) and R2.
  - With an empty R1, `ConnectedRepositories` shows the subtitle "No repository is connected. Connect one of the candidates below."
  - `GitHubCandidates` renders only when `candidates.githubConfigureUrl` is not null. It shows "The GitHub App has access to no repository of yours." and "Configure on GitHub".
- **`/api/v1/me`:** `features.githubApp` is `{slug, installUrl}` or null (no App configured, or a local sign-in). The SPA does not read it.
- **Connect:** `CandidateConnection.tsx` connects a candidate through `repositoriesAction`, which posts R3.
- **After an install:** the GitHub App setup URL `/github/installed` signs in again and redirects to `/repositories`.
- **Repository page:** `RepositoryRoute.tsx` shows the header, `StartReviewForm`, and `ReviewList`. With no open review, the list says "This repository has no open reviews."
- **Tokens:** T1 `GET /api/v1/tokens` lists the tokens. Settings has "Create token" with the MCP snippet.
- **Docs:** `docs/user/getting-started.md` has the sections sign in, install the GitHub App, connect a repository, start a review, and next steps. F65 gives the headings IDs.
- **E2E:** `VerticalSliceTest` and `SignInTest` start at `/` or `/repositories`.
- **Primer:** `Blankslate` exists only in `@primer/react/experimental`. The steps need their own layout.

## Decisions

The four steps:

| # | Step | Done when | Where |
|---|---|---|---|
| 1 | Connect a repository | R1 lists a repository | `/repositories` |
| 2 | Pick a point to review | the repository has an open review | `/repositories/:id`: a branch, a tag, or one of the latest commits (F68) |
| 3 | Start a review | the repository has an open review | `/repositories/:id` |
| 4 | Connect your agent | T1 lists a token | `/settings`, at any time |

- **`/`:** the index route redirects to `/repositories`. `HomeRoute.tsx` is deleted, and the shell route `/` stays.
- **`/repositories` with an empty R1** shows the onboarding screen in place of the tables. An installed GitHub App without a connected repository counts as nothing connected.
  - Heading: "Welcome to Slopmower". Sentence: "Review a whole codebase at one commit. Each comment becomes a change request that your coding agent picks up."
  - `OnboardingSteps.tsx` with a CSS module: an ordered list of the four steps. Each step has a number or a check icon, a title, one sentence, a state (done, current, upcoming), and a "Learn more" link to its `getting-started` heading.
  - Step 1 is open and holds the actions of the instance:
    - **GitHub App configured** (`me.features.githubApp`):
      - without GitHub candidates, the primary button "Install the GitHub App" (`installUrl`), with "Choose the account and the repositories that Slopmower may read. GitHub sends you back here.";
      - with candidates, the candidates with "Connect", and "Add repositories on GitHub" (`githubConfigureUrl`).
    - **Local directories on** (`me.features.localRepositories`): the local candidates with "Connect".
    - **Neither:** an info Banner "This instance has no repository source. The operator sets up a GitHub App or APP_LOCAL_REPOSITORIES_DIR."
  - A connect from the onboarding redirects to `/repositories/:id`, with the ID from the R3 response.
- **`/repositories/:id` with no open review** shows the compact steps "Getting started, step 2 of 4", with step 1 done.
  - "Start a review", with the latest commits, comes first.
  - The empty review list is hidden.
  - The first review ends it.
- **Step 4:** the two loaders read T1 only while the steps show. The step links to `/settings` and to `/docs/connect-your-agent`.
- **No dismiss state:** the steps show while the data says they are not done.

## Depends on

- F66: the local section.
- F68: the latest commits for step 2.
- F65: the headings IDs for "Learn more".

## In scope

- `assets/router.tsx` (index redirect), deleting `assets/routes/HomeRoute.tsx`.
- `RepositoriesRoute.tsx`, `RepositoryRoute.tsx`, `repositoriesAction`, and the new `assets/onboarding/OnboardingSteps.tsx` with a CSS module and its step components.
- UI specs and the journey `Repositories.spec.ts`.
- `docs/user/getting-started.md`, rewritten to the four steps, one heading per step.
- `docs/architecture.md` section 8 Screens: the Home and Repositories rows.

## Out of scope

- A dismiss button.
- An onboarding for agents.
- Illustrations other than Octicons.
- The review tour (F70).

## Acceptance tests

- **UI** `Onboarding.spec.ts`:
  - GitHub without candidates: "Install the GitHub App" links to `installUrl`.
  - GitHub with candidates: "Connect" posts R3 and opens `/repositories/:id`.
  - Local candidates show with "Connect".
  - Neither source: the info banner.
  - With a token, step 4 is done.
  - `/` opens `/repositories`.
- **UI** `RepositoryGettingStarted.spec.ts`: with no open review, the steps show "step 2 of 4" and "Start a review" comes first. With an open review, the steps are absent.
- **E2E:** `VerticalSliceTest` and `SignInTest` stay green.

## Verification

- `make check` is green.
- `make journeys`: "Onboarding, GitHub, no installation", "Onboarding, candidates", "Onboarding, local", "Repository, getting started".
