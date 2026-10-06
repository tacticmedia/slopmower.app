# F04 Code navigation

Workflow, conventions, and status: [feature track](features.md).

## Goal

From a name in the code view, the reviewer jumps to its declaration at the review commit, in the same file or in another file. Examples: a class name in `new X()`, `X::method()`, a type declaration, `extends`, `implements`, or a function call. Back returns to where the reviewer was.

## Facts

- The symbol index (M26) stores declarations only. Each `symbol` row is keyed per blob and has a kind, an FQN, a name, a parent FQN, and a line range. It is indexed by `(tenant_id, repository_id, blob_sha)`, with no index on `fqn`. `indexed_file` maps a commit's paths to blob SHAs. No query searches symbols across a commit.
- `PhpSymbolExtractor` already runs php-parser 5.9's `NameResolver`, so the resolved name of every reference exists during parsing. The collector keeps only declarations and discards references.
- The index covers PHP only (`docs/backlog/more-languages.md`).
- `@pierre/diffs` 1.5.1 has `onTokenClick`, `onTokenEnter`, and `onTokenLeave`. The props carry the line number, the character range, and the token text. They need `useTokenTransformer` in the worker pool's render options; `assets/code/workerPool.ts` sets only `theme` today. This is inferred from the library code and must be confirmed by a spike first.
- Deep links exist: `/reviews/{id}/files/{path}#L10-L20` (`assets/code/lineHash.ts`, `assets/review/fileHref.ts`).

## Decisions

- **Resolution on demand.** The server parses the file, finds the name at the clicked position, resolves it with `NameResolver`, and looks up declarations with that FQN among the symbols of the review commit. No reference index.
- **A new additive endpoint.** For example `GET /api/v1/reviews/{reviewId}/declarations?path=&line=&column=`, returning a list of targets `{path, fqn, kind, range}`, zero or more. It is specified in `docs/specs/rest-api.md` with a new schema and a fixture. A new endpoint is additive and allowed in v1. It needs a cross-tenant case and an inventory entry. MCP gets no tool: agents read the code themselves.
- **Static names only:** class-like names in any position, `X::method()`, `X::CONST`, `X::$prop`, enum cases, function calls, and constants, with PHP's namespace fallback for functions and constants. A method call on a variable (`$x->foo()`) needs type inference and returns no target.
- **A migration** adds an index on `symbol (tenant_id, repository_id, fqn)`.
- **Front end:**
  - Ctrl or Cmd plus click on a token jumps; a plain click keeps the text selection.
  - One target navigates to `path#Lstart-Lend`, or scrolls when the target is in the same file. Several targets open a small menu. No target shows "No declaration in this commit", for example for a vendor class that is not committed.
  - Navigation pushes a history entry, so Back returns.

## Open questions for Jan

- **Keyboard path:** a read-only view has no caret. Option: a "Go to declaration" field in the file header that takes a name and resolves it like a click. Decide before the start: D31 requires keyboard access for comment features, and this feature may need the same.
- **Modifier:** Ctrl or Cmd plus click, or a plain click on a name while a "Navigate" mode is on.

## Depends on

M26, M27. An additive contract change.

## In scope

- Spike: confirm that token events fire with the worker pool, and that `useTokenTransformer` does not break the blank-view fix in `CodeFile.tsx`.
- The spec (endpoint, schema, fixture, error cases: a non-PHP path or no clone gives 422 or 409, as V9 does), the migration, and the endpoint in a controller. The name-at-position resolution lives with the existing index code. Only the endpoint uses it, so no new service with one consumer.
- The front end: modifier-click on a token, the hover underline while the modifier is held, the target menu, and history navigation.
- Docs: `docs/user/reviewing.md`, the developer guide (V-endpoint list, `App\Index`), and architecture section 7 for the endpoint list.

## Out of scope

- Type inference for method calls on variables.
- Find references, and quick open by file or symbol name (a separate candidate).
- Languages other than PHP.

## Acceptance tests

- **U:** name-at-position resolution. One case each for a class name in `new`, a static method call, a namespaced function call with fallback, and `$x->foo()` returning nothing.
- **F** `DeclarationsTest`:
  - A class name resolves to its file and range, and the response validates against the schema.
  - An unknown name gives an empty list.
  - Two declarations with one FQN give two targets.
  - A cross-tenant review ID gives 404.
- **UI** `tests/ui/CodeNavigation.spec.ts`:
  - Modifier-click on a class name opens its declaration with the range selected, and Back returns to the previous line.
  - No target shows the message.

## Verification

- `make check` is green, and `doctrine:schema:validate` passes.
- Manual check: in this repository's review, Cmd-click `CommandExecutor` in `RequestCommands.php`. Its file opens at the class declaration, and Back returns.
