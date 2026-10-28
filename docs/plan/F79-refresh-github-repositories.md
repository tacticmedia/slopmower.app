# F79 Refresh the GitHub repositories

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-10-28: "I need a Refresh button above the list of repositories so that I can update the list when I add something in Github."

## Goal

After a change of the App repositories on GitHub, the user lists the GitHub repositories again with one click, without a sign-out.

## Facts

Verified on 2026-10-28:

- R2 of `docs/specs/rest-api.md`: the GitHub candidates are the list of the last sign-in. The `installation_repositories.added` webhook updates it only for installations on the own account of the user. "To refresh the list, the user signs in again through `/connect/github`. The setup URL `/github/installed` does that automatically."
- `GitHubInstalledController` redirects to `/connect/github` with `_target_path` `/repositories`. `InstallationSync` lists the installations and the repositories at that sign-in.
- The user token is never stored, so the server cannot list again without a sign-in.
- "Configure on GitHub" on an existing installation does not send the user back: the App registration has no "Redirect on update".
- `githubConfigureUrl` of R2 is null without an App and for a local sign-in.

## Decisions

- "Refresh" is a link to `/github/installed`. No new endpoint.
- It shows where the GitHub candidates show with `githubConfigureUrl`: in the actions of "GitHub repositories" before "Configure on GitHub", and in the onboarding step before "Add repositories on GitHub".

## Depends on

None.

## In scope

- `assets/routes/repositories/GitHubCandidates.tsx`, `assets/onboarding/ConnectRepositoryActions.tsx`.
- `docs/user/repositories.md`, `docs/user/getting-started.md`, the Repositories row of the screen table of `docs/architecture.md`.

## Out of scope

- A refresh of the local directories. The server scans them again on every load.
- "Redirect on update" in the App registration.

## Acceptance tests

- **UI** `GitHubCandidates.spec.ts`: "Refresh" links to `/github/installed` on the repositories screen.
- **UI** `Onboarding.spec.ts`: "Refresh" links to `/github/installed` in the GitHub step.
- `GitHubInstalledRouteTest` covers the redirect.

## Verification

`make check`.
