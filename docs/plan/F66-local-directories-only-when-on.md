# F66 Local directories only when on

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-19: remove "Local repositories" when the environment variable is empty. A SaaS deployment never has it, so the UI must not promote it.

## Goal

The repositories page shows the "Local directories" section only on an instance with `APP_LOCAL_REPOSITORIES_DIR` set.

## Facts

Verified on 2026-10-19:

- `assets/routes/repositories/RepositoriesRoute.tsx:49-59` always renders `LocalCandidates` with `enabled={me.features.localRepositories}`.
- `LocalCandidates.tsx` (title "Local directories", `data-testid="local-candidates"`):
  - while disabled, it shows the Banner "Local repositories are off" (`data-testid="local-repositories-off"`);
  - with no candidates, it shows "The local repositories directory contains no git repository."
- `me.features.localRepositories` is `'' !== $localRepositoriesDir` (`src/Controller/Api/MeController.php:64-67`), where `$localRepositoriesDir` is `APP_LOCAL_REPOSITORIES_DIR`.
- R2 `localEnabled` and R3 use `LocalRepositoryDiscovery::isEnabled()`, which also requires a readable directory. The SPA does not read `localEnabled`.
- Tests that read the section: UI `Repositories.spec.ts` (the `local-repositories-off` notice) and the journey `Repositories.spec.ts`.

## Decisions

- The flag that decides is `me.features.localRepositories`, the environment variable, as Jan asked.
- `LocalCandidates` loses its `enabled` prop and the banner.
- A configured directory without repositories keeps its empty text, because the operator turned the feature on.

## Depends on

F64.

## In scope

- `RepositoriesRoute.tsx`, `LocalCandidates.tsx`.
- UI `Repositories.spec.ts`, the journey `Repositories.spec.ts`.

## Out of scope

- The public docs page `local-run.md`. F71 moves it under "Self-hosting".
- R2 `localEnabled`.

## Acceptance tests

- **UI:** with `features.localRepositories: false`, `[data-testid="local-candidates"]` and `[data-testid="local-repositories-off"]` are absent. With `true`, the section shows its candidates.

## Verification

- `make check` is green.
- `make journeys`: the repositories page.
