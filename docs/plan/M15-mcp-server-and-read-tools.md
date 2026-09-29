# M15 MCP server and read tools

Workflow, conventions, and status: [README](README.md).

## Goal

The `holistic-review` MCP server over Streamable HTTP at `/_mcp`, with the five read tools. The contract is docs/specs/mcp.md.

## Depends on

M13, M14.

## In scope

- `composer require symfony/mcp-bundle:0.14.1 mcp/sdk:0.8.1 nyholm/psr7`. The first two are exact pins.
- A hand-written `config/packages/mcp.yaml`, because the bundle has no recipe. Confirm every key with `make console c="config:dump-reference mcp"`:
  - server `holistic-review`, version `1.0.0`, the description of docs/specs/mcp.md section 1;
  - transports: HTTP only, no stdio;
  - `http.path: /_mcp`;
  - `http.allowed_hosts: ['%env(APP_PUBLIC_HOST)%']`, with `APP_PUBLIC_HOST=localhost` in `.env`. Read how the bundle compares the `Host` header, with or without the port, so that `localhost:8000` and `localhost:8081` pass;
  - the session store `file` with `directory: '%kernel.project_dir%/var/mcp-sessions'`, decision D40. The default directory is under `%kernel.cache_dir%`, which `cache:clear` removes;
  - tool discovery in `src/Mcp/Tool`;
  - `instructions`: the text of docs/specs/mcp.md section 2, through the parameter `app.mcp_instructions`.
- `config/routes/mcp.yaml` with the route type `mcp`.
- Resolve the double PSR-17 aliases of the `nyholm/psr7` and `php-http/discovery` recipes. Keep one set.
- Firewall `mcp`: pattern `^/_mcp`, `stateless: true`, `access_token` with the M14 handler, role `ROLE_AGENT`. `BearerAuthenticationFailure` of M14 is its `failure_handler` and its `entry_point`, so a call without a token also gets the Error body. A wrong token gets HTTP 401 before JSON-RPC, with `WWW-Authenticate: Bearer realm="holistic-review", error="invalid_token"` and the Error JSON body. `/.well-known/oauth-protected-resource` answers 404, decision D18.
- `app:schema:bundle`: generates `src/Mcp/BundledSchemas.php` with one `public const array` per output root. Each constant is a bundled copy of a schema: the root with `type: "object"`, its `properties` and `required`, and a `$defs` section with every referenced definition, with the `$ref`s rewritten to `#/$defs/<Name>`. Attribute arguments must be constant expressions, so the schemas live in constants. The file is generated, so add it to the exception list of `FileSizeLimitTest`.
- Read tools. One class per tool in `src/Mcp/Tool`, each with one `#[McpTool]` method. Parameters are scalars and backed enums only. Names, titles, descriptions, `#[Schema]` constraints, annotations, and output schemas equal docs/specs/mcp.md section 4:
  - `list_repositories(cursor, limit)`;
  - `list_reviews(repository, includeClosed, cursor, limit)`. `repository` resolves as docs/specs/README.md section 7 says: a ULID, a GitHub `owner/name`, or a local directory name;
  - `list_requests(review, status, awaiting, path, outdated, cursor, limit)`;
  - `get_updates(review, cursor, limit)`, with `includeOwn = false`;
  - `get_request(id, at, context)`. Until M29 the query returns `location: null` and `excerpt: null`.
- `McpToolRunner`:
  - calls the same queries and normalizers as REST, so `structuredContent` equals the REST JSON and `content[0].text` holds the same JSON as text;
  - maps a `DomainError` to `ToolCallException` with the Error JSON `{"error":{"code","message","details"}}` as its message. The SDK then returns `isError: true` without `structuredContent`;
  - catches every other `Throwable`, logs it, and throws `ToolCallException` with code `internal_error`, so an agent never gets a bare -32603;
  - semantic input checks, for example a whitespace-only value, give `isError` with `invalid_input`.

## Out of scope

- Write tools: M16.
- Locations for `at`: M29.

## Files (representative)

- `config/packages/mcp.yaml`, `config/routes/mcp.yaml`, `config/packages/security.yaml`, `.env`.
- `src/Mcp/Tool/*.php`, `src/Mcp/McpToolRunner.php`, `src/Mcp/BundledSchemas.php`, `src/Command/BundleSchemasCommand.php`.
- `tests/Support/Mcp/McpTestClient.php`: sends JSON-RPC over HTTP, reads JSON and SSE responses, keeps `Mcp-Session-Id`, and sends `MCP-Protocol-Version`.
- `tests/Functional/Mcp/*Test.php`, `tests/Fixtures/mcp/tools-list.json`.

## Tasks (ordered)

1. Install the three packages. Check that `config/bundles.php` has `Symfony\AI\McpBundle\McpBundle`.
2. Write the configuration, the route, and the firewall.
3. Write `app:schema:bundle` and generate `BundledSchemas.php`.
4. Write `McpToolRunner` and the five tools.
5. Write the test client and the wire tests. `docs/specs/fixtures/` has no `tools/list` fixture. Copy the input schemas of the five read tools of docs/specs/mcp.md section 4 verbatim into `tests/Fixtures/mcp/tools-list.json`.
6. Add the cross-tenant cases for the tools.

## Acceptance tests

All are functional tests on the wire level.

- **F `McpHandshakeTest`** proves the session-based protocol.
  - "initialize with protocol 2025-11-25, the initialized notification, and ping succeed"
  - "The session is stored under var/mcp-sessions"
- **F `McpDiscoverTest`** proves the stateless protocol.
  - "server/discover of protocol 2026-07-28 works without a session"
- **F `McpAuthTest`** proves authentication.
  - "A call without a token gets 401 with WWW-Authenticate"
  - "A revoked token gets 401"
  - "A session cookie alone gets 401"
  - "Every 401 has the Error body"
  - "/.well-known/oauth-protected-resource answers 404"
- **F `ToolsListContractTest`** proves the tool contract. M16 extends it to all 12 tools.
  - "tools/list names, input schemas, and annotations of the five read tools equal docs/specs"
- **F `BundledSchemasUpToDateTest`** proves that the output schemas follow the schema files.
  - "BundledSchemas.php equals the output of app:schema:bundle"
- **F `ReadToolsTest`** proves the read tools. Every `structuredContent` validates against the tool's output schema.
  - "list_reviews returns only open reviews by default"
  - "list_reviews returns both reviews when two are open, and closed ones with includeClosed"
  - "list_reviews resolves the repository by ID and by local directory name"
  - "get_updates returns a cursor that the next call accepts"
  - "content text equals the structuredContent JSON"
- **F `McpErrorMappingTest`** proves the error mapping of docs/specs/mcp.md section 5.
  - "not_found gives isError with the Error JSON as text and no structuredContent"
  - "A bad enum value gives JSON-RPC error -32602"
  - "An unexpected exception gives isError with internal_error, not -32603"
- **F `McpCrossTenantInventoryTest`** proves tenant isolation for every tool.
  - "Fails when a tool has no cross-tenant case"
  - "Each tool with the other tenant's IDs gives not_found"
  - "A scoped token sees one repository"
- **F `McpRestParityTest`** proves one data model for both adapters.
  - "get_request equals the REST body of Q3"
  - "list_requests equals the REST body of Q1"

## Verification commands

```sh
make test
make lint
make check
```

By hand:

1. Sign in locally in the browser. Create a token with T2 through a `fetch()` call from the browser console.
2. Send `initialize` with `curl` and the token.
3. Run `claude mcp add --transport http holistic-review http://localhost:8000/_mcp --header "Authorization: Bearer $T"` and list the tools in Claude Code.

## Done

An MCP client can discover and read reviews.
