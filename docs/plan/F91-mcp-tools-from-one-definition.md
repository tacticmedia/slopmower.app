# F91 MCP tools from one definition

Workflow, conventions, and status: [feature track](features.md). Revised step 3 of the [declarative model](declarative-model.md#re-assessment-after-step-1), after [F90](F90-one-request-command-path.md).

## Goal

The name, title, description, input schema, annotations, and output schema of each MCP tool are written once, in `docs/specs/mcp-tools.json`. The server registers its tools from that file, so a change of a tool contract is one edit of one file. `tools/list` answers exactly as before.

## Facts

Verified on 2026-10-29, with `docs/specs/mcp.md` and `src/Mcp/McpTools.php` read verbatim:

- The contract of each of the 18 tools is written five times:
  - `mcp.md` section 4: a description block, a PHP snippet with the attributes, and the JSON input schema;
  - `McpTools`: `#[McpTool]`, the `#[Schema]` attributes, and the `@param` descriptions;
  - `tests/Fixtures/mcp/tools-list.json`, which `ToolsListContractTest` compares with `tools/list`;
  - the tool table of architecture section 7.3.
- The SDK builds each input schema from the PHP signature, the `@param` descriptions, and `#[Schema]`. `mcp.md` section 3 lists the quirks of that generation, for example the type order `["null","string"]`.
- `RegistryInterface::registerTool(Tool, handler)` registers a tool from data. The bundle tags every `LoaderInterface` service `mcp.loader`. For an `[object, method]` handler, `ReferenceHandler` reads the method by reflection and casts each argument to its parameter type, as for an attribute tool. `Tool::fromArray()` reads the members of `tools/list`.
- `app:schema:bundle` writes `src/Mcp/BundledSchemas.php` from `docs/specs/schemas`, and a test fails when the file is out of date.
- The tool methods of `McpTools` are the camel case of the tool names.

## Decisions

- **D99 One tool definition.** `docs/specs/mcp-tools.json` holds the 18 tools in the order of `tools/list`: `name`, `title`, `description`, `inputSchema`, `annotations`, and `outputSchema` as a reference into `docs/specs/schemas`. `app:schema:bundle` writes them into `BundledSchemas::TOOLS`, with each output schema as the bundled constant of that reference. `App\Mcp\ToolLoader` registers each tool with the handler `[McpTools, <camel case of the name>]`. `McpTools` has no `#[McpTool]`, no `#[Schema]`, and no `@param` descriptions, and `config/packages/mcp.yaml` names no tool class.
- **Checks.** `ToolsListContractTest` compares `tools/list`, output schemas included, with `mcp-tools.json`. A new test checks that the parameters of each handler match its input schema: the names in order, and a default exactly for each property that is not required. `tests/Fixtures/mcp/tools-list.json` goes.
- **Docs.** `mcp.md` section 4 keeps the table and, per tool, the output, the behaviour, the errors, and the example, and links `mcp-tools.json` for the rest. Section 3 describes the loader in place of the attributes and the schema generation. Architecture section 7.3 links `mcp.md` in place of its tool table.

## Depends on

[F90](F90-one-request-command-path.md).

## In scope

`docs/specs/mcp-tools.json` (new), `src/Command/BundleSchemasCommand.php`, `src/Mcp/BundledSchemas.php` (generated), `src/Mcp/ToolLoader.php` (new), `src/Mcp/McpTools.php`, `config/packages/mcp.yaml`, `tests/Functional/Mcp/ToolsListContractTest.php`, the new handler test, `tests/Fixtures/mcp/tools-list.json` (deleted), `docs/specs/mcp.md`, `docs/specs/README.md`, `docs/architecture.md` (section 7.3, D99), `docs/developer-guide.md`.

## Out of scope

- Any change of a tool contract: every name, description, schema, and annotation stays as it is.
- The REST API.

## Acceptance tests

- `tools/list` equals `mcp-tools.json`, output schemas included.
- The parameters of each handler match its input schema.
- The existing MCP tests pass unchanged: the wire tests, `McpRestParityTest`, `McpErrorMappingTest`, and the -32602 schema violations.

## Verification

`make cs`, `make lint`, `make stan`, `make test`, `make front`, `make ui`, and `make e2e`. A `tools/list` of the dev server before and after the change, compared as JSON.
