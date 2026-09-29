# M33 GitHub App repositories

Workflow, conventions, and status: [README](README.md).

## Goal

Connect the repositories of the user's GitHub App installations, and fetch them over HTTPS with installation tokens. Decision D24 covers both repository sources.

## Depends on

M32, M09.

## In scope

- `composer require lcobucci/jwt`, version 5.6.0.
- `GitHubAppJwtFactory`: RS256, `iat` 60 seconds in the past, a lifetime of at most 10 minutes, the client ID as issuer, the key from `GITHUB_APP_PRIVATE_KEY`.
- `InstallationTokenProvider`: `POST /app/installations/{id}/access_tokens`, limited to one repository and `contents: read`. The token is cached until 5 minutes before it expires.
- `GitHubInstallation` entity: `installationId`, `accountLogin`, `accountType` with `User` and `Organization`, `suspendedAt` or null. A nullable foreign key on `Repository`.
- `GitHubCandidate` entity: the per-tenant candidate list from the last sign-in: `repositoryId`, `installationId`, `accountLogin`, `owner`, `name`, `private`, `defaultBranch`, `htmlUrl`, `listedAt`.
- A migration.
- The sync at sign-in, as docs/specs/rest-api.md R2 says:
  - with the user token, which is never stored: `GET /user/installations` and `GET /user/installations/{id}/repositories`;
  - records the installations and the candidates;
  - a connected repository that the user can no longer access becomes `access = removed`: its clone and index are deleted at once, `sync.state` becomes `unavailable`, and a `repository` live update is published. Its reviews stay, decision D22.
- R2 lists the GitHub candidates with `githubListedAt` and `githubConfigureUrl`. `features.githubApp` of `/api/v1/me` holds `slug` and `installUrl` when the App is configured and the user signed in with GitHub, else null.
- `ConnectGitHubRepository` through R3 with `{"source":"github","githubRepositoryId":<id>}`. An ID that is not a candidate gives 404. A new repository starts with `sync.state = cloning` and a clone job.
- `GitHubFetchCommand`:
  - the installation token reaches git only as an environment extraheader, decision P8: `GIT_CONFIG_COUNT=1`, `GIT_CONFIG_KEY_0=http.https://github.com/.extraheader`, `GIT_CONFIG_VALUE_0=AUTHORIZATION: basic <base64 of x-access-token:token>`;
  - a configurable git base URL, so tests fetch from a local bare repository;
  - a suspended installation makes the fetch fail with `lastError` "GitHub App installation suspended".
- `GET /github/installed`, session only: the App's setup URL. It redirects through `/connect/github?_target_path=/repositories`, so the sign-in lists the installations again.
- A GitHub section on the repositories screen: the GitHub candidates, connect, and a "Configure on GitHub" link from `githubConfigureUrl`.
- A cross-tenant case for every new route.

## Out of scope

- Webhooks: M34.

## Files (representative)

- `src/GitHub/*.php`, `src/Source/GitHubFetchCommand.php`.
- `src/Entity/GitHubInstallation.php`, `src/Entity/GitHubCandidate.php`, `migrations/Version*.php`.
- `src/Controller/GitHubInstalledController.php`.
- `assets/routes/repositories/*` changes.
- `tests/Fixtures/github/*.json`, `tests/Unit/GitHub/*Test.php`, `tests/Functional/GitHub/*Test.php`, `tests/E2E/GitHubCandidatesTest.php`.

## Tasks (ordered)

1. Install `lcobucci/jwt`. Write the JWT factory and the token provider.
2. Write the entities and run `make migration`.
3. Write the sign-in sync and hook it into `GitHubUserProvider`.
4. Write the connect use case and the fetch command.
5. Write `/github/installed` and the UI section.
6. Record the GitHub responses and write the tests.

## Acceptance tests

- **U `GitHubAppJwtTest`** proves the App JWT.
  - "The JWT has iat 60 seconds in the past, a lifetime of at most 10 minutes, and the client ID as issuer"
  - "The signature verifies with the public key"
- **F `InstallationTokenTest`** proves token requests. It uses `mockTime()`.
  - "The request limits the token to one repository and contents read"
  - "The token is reused until 5 minutes before it expires"
- **F `InstallationSyncTest`** proves the sign-in sync.
  - "Sign-in records the installations and the candidates"
  - "A connected repository that is no longer listed becomes removed, and its clone and index are deleted"
  - "R2 lists the GitHub candidates with githubListedAt"
- **F `ConnectGitHubRepositoryTest`** proves the GitHub connect.
  - "A candidate connects with 201"
  - "An ID that is not a candidate gives 404"
- **F `GitHubFetchSecretTest`** proves that the token never leaks.
  - "The token is not in the git configuration, the argv, exception messages, or logs"
  - "A fetch through the base-URL override from a local bare repository works"
- **F `GitHubInstalledRouteTest`** proves the setup URL.
  - "/github/installed redirects through /connect/github with _target_path /repositories"
- **E `GitHubCandidatesTest`** proves the UI section with candidates that the test stores.
  - "GitHub candidates and the Configure on GitHub link show on the repositories screen"

## Verification commands

```sh
make migration
make db-test
make check
```

## Done

A GitHub repository connects and fetches with an installation token that appears nowhere on disk or in logs.
