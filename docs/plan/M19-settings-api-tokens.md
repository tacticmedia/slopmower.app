# M19 Settings: API tokens

Workflow, conventions, and status: [README](README.md).

## Goal

Create, scope, expire, and revoke agent tokens in the UI, decision D30.

## Depends on

M14, M17.

## In scope

- `/settings`: a Primer `DataTable` of T1 with the columns name, scope, created, last used, expiry, and state. The name is the agent name. The scope shows "All repositories" or the repository name.
- A create `Dialog` for T2:
  - the name, with the rule of `TokenName` as a hint;
  - the scope: all repositories, or one repository from R1;
  - an optional expiry date.
- A one-time display of the secret:
  - a copy button;
  - a `.mcp.json` snippet built from `links.mcpUrl` of `/api/v1/me`, with `${SLOPMOWER_TOKEN}` in the `Authorization` header;
  - a note to put the secret into the `SLOPMOWER_TOKEN` environment variable.
- Revoke through T3, with a confirmation.

## Out of scope

- Account and repository deletion: M31.
- The skill link: M23.

## Files (representative)

- `assets/routes/settings/*.tsx`.
- `assets/settings/TokenTable.tsx`, `CreateTokenDialog.tsx`, `McpConfigSnippet.tsx`, `assets/settings/mcpConfig.ts`, CSS modules.
- `assets/settings/mcpConfig.test.ts`.
- `tests/E2E/TokenSettingsTest.php`, `tests/E2E/ScopedTokenTest.php`, `tests/Support/E2E/McpHttpClient.php`.

## Tasks (ordered)

1. Build the table, the create dialog, the one-time display, and the revoke confirmation.
2. Write `McpHttpClient` for E2E tests: it sends MCP JSON-RPC to `http://localhost:8081/_mcp` from the PHPUnit process.
3. Write the tests.

## Acceptance tests

- **V `McpConfigTest`** proves the snippet.
  - "The snippet has the server slopmower, type http, the /_mcp URL, and the Authorization header with ${SLOPMOWER_TOKEN}"
- **E `TokenSettingsTest`** proves the token flow.
  - "The secret is shown once and matches the token format"
  - "The page source after a reload does not contain the secret"
  - "MCP initialize works with the new token"
  - "After revoke, the same call gets 401"
- **E `ScopedTokenTest`** proves scope and expiry in the UI.
  - "A token scoped to one repository shows that repository in the table"
  - "list_repositories with that token returns only that repository"
  - "The expiry date shows in the table"

## Verification commands

```sh
make front
make e2e
make check
```

## Done

An agent can be connected without `curl`.
