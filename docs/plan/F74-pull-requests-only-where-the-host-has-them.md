# F74 Pull requests only where the host has them

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-10-20: "There are no pull requests when working with a local repository, so the function must be disabled when reviewing a local repository." In the same request, Jan asked that the architecture allow other git hosts later: a self-hosted remote with an uploaded access key or SSH key, GitLab, GitHub Enterprise, and Bitbucket. They are not built now.

## Goal

- A local repository refuses a pull request link. A link that already exists can still be removed.
- One server method says whether a source has pull requests, so a new source sets its own value.
- Decision D83 records the host model, where a new source plugs in, and the GitHub couplings that a new host must lift.

## Facts

Verified on 2026-10-20:

- **Link paths:**
  - Q17 (`PullRequestLinkController.php:31`) and MCP `link_pull_request` (`McpTools.php:222`) reach `RequestCommands.php:175`, then `RequestDecider.php:232` and `:321`, then `PullRequestUrl::forRepository()` (`src/Domain/Request/PullRequestUrl.php:38-54`).
  - For a non-GitHub source, `forRepository()` accepts any `https` URL.
  - Unlink calls `forRepository()` too (`RequestDecider.php:355`), and its catch recovers only for GitHub (`:357-359`).
  - `RequestDecider::pullRequestUrlViolations()` (`:318`) is used by link only.
- **Already GitHub only:**
  - R8 refuses a local repository (`src/Entity/Repository.php:198-205`), and the SPA hides its switch (`RepositoryHeader.tsx:34`).
  - State reads are queued for GitHub only (`RequestCommands.php:184`), and check runs skip other sources (`CheckRunHandler.php:180`).
  - The request sidebar "Development" section lists only `request.pullRequests`, and the SPA has no link control.
- **The agent's view:** `AllowedActions` (`src/Domain/Request/AllowedActions.php:46`) lists `link_pull_request` from the request state only. `ClaimReleaseToolTest.php:66` asserts this on a local source.
- **GitHub coupling:**
  - About 33 code sites in 17 files branch on `RepositorySource`. 6 places hard-code a GitHub host: `InstallationTokenProvider::API`, `FetchCommand::EXTRAHEADER_KEY`, the `GitHubApp` install URL, the `RepositoryNormalizer` `htmlUrl`, `PullRequestUrl`, and the `github.client` scope.
  - The git transport (`FetchCommand`, `BareClone`, `GitRunner`) is source-neutral.
  - `RepositorySource` is a closed set (`docs/specs/README.md:193`). Before the first release, a new value is an amendment in README section 8.10.
- **Local link fixtures:** `PullRequestLinkTest.php:27,64-76`, `PullRequestUrlTest.php:58-64`, `McpRestParityTest.php:62,210-211`, `ProjectionConsistencyTest.php:114`, and `InputFixturesTest.php:80,94,147` (the local `billing` fixture).

## Decisions

- `RepositorySource::hasPullRequests(): bool`: true for `github`, false for `local`.
- The refusal is 422 `invalid_input` on `url`, "This repository has no pull requests.". It goes in the link path only (`pullRequestUrlViolations()`), not in `forRepository()`, so unlink still works.
- `allowedActions` stays state-based. A host without pull requests refuses the link as input, the same as an invalid URL.
- No `capabilities` response property in F74. F76 adds it to `Review` and `Request`, where the SPA needs it.
- The closed sets `RepositorySource` and `signInMethod`, and the if/else of `repository.schema.json:90-118`, stay as they are. D83 lists them for the first other host.
- **D83 Repository hosts:**
  - A source is how Slopmower reaches the code. Its capabilities are what the host offers. Identity (sign-in) is separate from the source.
  - Integration levels:
    - a local directory: code only;
    - a git remote with a stored credential: code, fetched by Refresh or a poll;
    - a hosted forge: sign-in, repository listing, push webhooks, pull requests, and checks.
  - Plug-in points of a new source:
    - a `RepositorySource` case and its capability methods;
    - a `FetchCommand` factory with its secret configuration (D38);
    - a connect flow (R2, R3);
    - optional webhooks at `/webhook/<source>`;
    - optional pull request reads and check writes.
  - Couplings that a new host must lift:
    - the 6 hard-coded GitHub hosts (GitHub Enterprise);
    - Marketplace billing (D65);
    - name resolution by `/` (`RepositoryQuery.php:91-105`);
    - the closed sets, to open before the first release when another host is planned;
    - the schema if/else.

## Depends on

Nothing.

## In scope

- **Specs:**
  - Q17 in `docs/specs/rest-api-requests.md` (the refusal), and `:83` (`allowedActions` is state-based).
  - `docs/specs/mcp.md` sections 2 and 4.
  - The 8.10 amendment entry in `docs/specs/README.md`. It starts with "Not additive:", because this is tighter validation, as in the D75 entry.
- **Code:** `RepositorySource::hasPullRequests()`, and the refusal in `RequestDecider::pullRequestUrlViolations()`.
- **MCP:** the server instructions in `config/services.yaml:29`, the `link_pull_request` description at `McpTools.php:213`, and `tests/Fixtures/mcp/tools-list.json`.
- **Docs:**
  - `docs/user/skill/SKILL.md` and its byte-equal copy `.claude/skills/slopmower/SKILL.md`: a local repository has no pull requests.
  - `docs/user/pull-requests.md`.
  - D83 in `docs/architecture.md` section 11, and a short note in section 4 "Repository sources".
- **Fixtures** that link on a local source move to a GitHub source or drop the link.

## Out of scope

- Any new source, credential storage, SSH transport, and GitHub Enterprise host settings.
- SPA changes. F76 adds the capability to the responses that the SPA reads.
- Opening the closed sets, and the schema rewrite.

## Acceptance tests

- **Functional (`PullRequestLinkTest`):**
  - The happy paths of Q17 and Q19 run on a GitHub repository.
  - "A local repository refuses a pull request link with invalid_input".
  - "A local repository still unlinks a link that exists", with a link event written before the change.
- **Unit:** `PullRequestUrlTest:58-64` "A local repository accepts any https URL" is removed or reversed. `RepositorySource::hasPullRequests()` gets one case per source.
- **MCP:** `McpRestParityTest` link and unlink run on a GitHub source. `link_pull_request` on a local source gives `invalid_input`.

## Verification

- `make check` is green.
