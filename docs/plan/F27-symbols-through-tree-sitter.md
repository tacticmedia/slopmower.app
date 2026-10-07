# F27 Symbols through tree-sitter

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-08 for symbols in JavaScript, TypeScript, Python, Go, and Rust, in a shape that takes more languages later. F27 builds the engine with JavaScript and TypeScript. [F29](F29-symbols-for-python-go-rust.md) adds Python, Go, and Rust. Together they implement the backlog item "More languages".

## Goal

The Symbols menu, exact-range symbol anchors, re-anchoring by FQN, quick open, and the F25 sticky scope lines work for `.js`, `.mjs`, `.cjs`, `.jsx`, `.ts`, `.mts`, `.cts`, and `.tsx` files as they do for PHP. A further language needs only:
- a grammar package;
- a query file;
- a row in the language table;
- a fixture test.

It needs no PHP class, migration, schema change, or front-end change, unless it adds a symbol kind.

## Facts

- `BlobSymbols` takes `PhpSymbolExtractor` directly (`src/Index/BlobSymbols.php:24`). S07 (`58bcc57`) removed the `SymbolExtractor` interface because it had one implementation, and the S07 report says a second language brings it back.
- `indexed_blob` and `symbol` have no language column, and `BlobSymbols` takes the language from the extractor. M26 report follow-up: a blob at a `.php` path and at a path of another language share one row set.
- `SymbolKind` and `SymbolRef.language` are open sets in v1 (specs README section 8). `SymbolKind.php` is a closed backed enum. `SymbolKind::from()` in `IndexSql` and `PayloadReader::enum()` refuse an unknown value.
- `FileContentNormalizer` maps file names to the V7 highlighting languages `javascript`, `jsx`, `typescript`, `tsx`, `python`, `go`, and `rust`, among others.
- V14 resolves a PHP name to FQNs and looks them up among all symbols of the commit (`IndexSql::DECLARATIONS`).
- `BlobSymbols::of()` serves V9, Q2 and V15 capture (`AnchorFactory`), and `Locator`. `Locator` serves Q3, Q9, V15, and the MCP tool `get_request`.
- Front end:
  - `symbolShortName()` puts `$` before every property;
  - `symbolItems()` groups by `parentFqn ?? (class-like ? fqn : top level)`;
  - `tests/ui/support/fakeReview.ts:365,437-439` answers symbols only for PHP.
- Tests:
  - `NoContainerMockingTest` forbids `getContainer()->set()`;
  - `tests/Support/Environment/EnvironmentVariables.php` sets an environment variable before boot;
  - `FileSizeLimitTest` scans `src`, `tests`, and `assets`.
- Runtime research on 2026-10-08, against primary sources:

| Option | Result |
|---|---|
| universal-ctags, trixie 5.9.20210829 | No `end` for JavaScript, TypeScript, or Rust. No TSX. No parse-error or doc-comment signal. |
| tree-sitter CLI 0.27.0 | No JSON. `tags` prints name ranges only. Compiles grammars with a C compiler at runtime. |
| xberg-io/tree-sitter-language-pack 1.21.0, PHP extension | No PHP 8.5 ZTS binary (FrankenPHP needs ZTS). No custom queries. Doc comment text without a position. |
| ast-grep 0.45.3 `outline` | Alpha. No doc comments, no parse errors. Drops or flattens nested declarations. |
| web-tree-sitter 0.27.0 on Node 24 | Own queries, `rootNode.hasError`, full node ranges, sibling access. Applies `#eq?`, `#match?`, and similar predicates, but not `#select-adjacent!` or `#strip!`. |

- Grammar versions on npm on 2026-10-08:
  - tree-sitter-javascript 0.25.0;
  - tree-sitter-typescript 0.23.2, ABI 14 (web-tree-sitter 0.26 and later reject wasm built by old CLIs, issue #5171);
  - each package ships a `.wasm` and a `queries/tags.scm`.
- Node 24.12 and later run erasable TypeScript without a build step.

## Decisions

D56 and D57 in [architecture section 11](../architecture.md#11-decisions).

- **Engine:**
  - a `symbols` HTTP service: Node 24 with web-tree-sitter, in its own image (Dockerfile stage `symbols`), with its own `symbols/package.json`;
  - PHP calls it through the scoped client `symbols.client` (`SYMBOLS_URL`), so there is no child process and `NoProcessOutsideGitTest` stays as it is;
  - every deployment runs the image and sets `SYMBOLS_URL`.
- **Wire format:**
  - `POST /v1/symbols?language=<id>` with the raw blob as `application/octet-stream`;
  - 200 `{parseError, symbols: [{kind, fqn, name, parentFqn, startLine, endLine, declarationStartLine, docStartLine}]}`;
  - 422 for an unknown language, 413 above 1 MiB;
  - `GET /healthz`.
- **Interface:** `SymbolExtractor` with `#[AutoconfigureTag]`, `languages(): list<string>`, and `extract(string $language, string $source): SymbolTable`.
  - Implementations: `PhpSymbolExtractor` and `TreeSitterSymbolExtractor`.
  - `BlobSymbols` takes them with `#[AutowireIterator]` and picks one by `FileLanguage::of($path)`.
  - V14 stays PHP only and injects `PhpSymbolExtractor`.
- **Language ids:** the V7 ids. `jsx` uses the JavaScript grammar, and `tsx` uses the TSX grammar with the TypeScript query. V9 `language` is `null` when no extractor supports the file.
- **Storage:**
  - `indexed_blob.language`, backfilled with `php`.
  - `BlobSymbols::of()` treats a row of another language as not indexed: it parses and does not store.
  - The index job and `storedSymbolCounts()` ignore the language, so the first writer wins.
  - `DECLARATIONS` keeps `indexed_blob.language = 'php'`, so a PHP reference never resolves to a file-local FQN of another language.
- **Failure:** a transport error or a 5xx from the service throws, and nothing is stored or cached. This affects only non-PHP files: V9, Q2, V15, the index job, and `Locator` for a non-PHP symbol anchor on a changed blob. They answer with the existing 500 problem response.
- **Parse rules:**
  - a tree with an error gives `parseError: true` and no symbols;
  - a parse over 2 s is a parse error;
  - the tree is deleted in a `finally`.
- **ETag:** V9 gets its own version, `v2`, while `/file` keeps `v1`.
- **Kinds:** `struct`, `type`, `module`, and `macro` are appended, in this order, to the schema, `SymbolKind.php`, and `common.ts`.
  - The containers are `class`, `interface`, `trait`, `enum`, `struct`, and `module`.
  - No rollback is possible once a row or an anchor event has a new kind.
- **FQNs:**
  - File-local, with the separator of the language. Functions and methods end with `()`.
  - Declarations inside function bodies are skipped.
  - `parentFqn` is the nearest enclosing container.
  - The FQN ends with `name` plus an optional `()` or `!`. A PHP property has `$` before the name.
- **Ranges:** D7 holds.
  - The declaration node is the outermost wrapper (`export_statement`, `ambient_declaration`), so `declarationStartLine` includes decorators, and a `/** */` before `export` counts.
  - A node that ends at column 0 ends on the line before.
  - TS overload signatures directly before an implementation with the same name join its range.
- **JavaScript and JSX, doc comment `/** */`:** class `A`, method `A.m()`, property `A.x`, function `f()`, top-level `const f = () =>` as function `f()`, top-level `const` as constant `X`.
- **TypeScript and TSX, doc comment `/** */`:** JavaScript plus interface `I` with `I.m()` and `I.p`, type `T`, enum `E` with enum_case `E.A`, and namespace as module `N` with `N.A`.
- **Queries are our own:** `symbols/queries/<language>.scm`, with capture names equal to `SymbolKind` values plus `@name`. The upstream `tags.scm` files are a reference only.
- **One row guard** for both extractors: valid UTF-8, no NUL, FQN within `SymbolRef::MAX_FQN_CHARACTERS`, `name` and `parentFqn` within their column lengths, and the FQN contains `name`.
- **Front end:**
  - `symbolShortName()` takes the FQN suffix from the last `name`, and `$` when it precedes the name.
  - The group id is `CLASS_LIKE_KINDS.has(kind) ? fqn : (parentFqn ?? TOP_LEVEL_GROUP)`, with the exported set `CLASS_LIKE_KINDS` including `struct` and `module`.

## Depends on

- M26, M27.
- F30 (a bug) goes first.
- F25 (in progress) reads V9 symbols for its sticky scope lines. Whichever of F25 and F27 lands second adds `struct` and `module` to that container set.

## In scope

- **Spike**, recorded in the report:
  - check the latest npm versions;
  - check that web-tree-sitter loads both grammar wasm files by file path in `node:24-trixie-slim`;
  - check that the grammar install scripts use prebuilds, or skip them with `--ignore-scripts`;
  - parse a TS 5.x fixture with `satisfies`, decorators, `using`, and const type parameters.
- **`symbols/`:**
  - `package.json`, its lock file, `src/server.ts`, `src/extract.ts`, `src/languages.ts`, and `queries/javascript.scm`, `queries/typescript.scm`;
  - `tsconfig.symbols.json` (`erasableSyntaxOnly`, `allowImportingTsExtensions`, `verbatimModuleSyntax`, `noEmit`, `types: ["node"]`), referenced from `tsconfig.json`.
- **Infrastructure:**
  - `Dockerfile`: stage `symbols`.
  - `compose.yaml`: service `symbols`, with the sources mounted read-only, `node --watch`, and a healthcheck. `php` depends on it being healthy.
  - `.env`: `SYMBOLS_URL=http://symbols:3000`.
  - `framework.yaml`: `symbols.client` with timeout 10, not mocked in `when@test`.
  - `Makefile`: `front` runs `npm ci --prefix symbols` when `symbols/node_modules` is missing. The `up` help text names the service.
  - Vitest `include` gets `symbols/**/*.test.ts`. `FileSizeLimitTest` scans `symbols`.
- **PHP:**
  - `src/Index/SymbolExtractor.php`, `src/Index/TreeSitterSymbolExtractor.php`, `src/Index/FileLanguage.php` (the map from `FileContentNormalizer`), and the shared row guard;
  - changes to `PhpSymbolExtractor`, `PhpSymbolCollector`, `BlobSymbols`, `IndexSql` (`insertBlobs`, `DECLARATIONS`), `DeclarationController`, `SymbolController`, `SymbolKind`, `IndexedBlob`, and `Symbol`;
  - a migration generated with `make migration`, then edited as in `Version20261006225541.php`: `DEFAULT NULL`, then `UPDATE`, then `SET NOT NULL`;
  - updated callers in `PhpSymbolExtractorTest` and `IndexReviewHandlerTest`.
- **Front end:**
  - `common.ts` `SYMBOL_KINDS`, `symbolMatch.ts`, `symbolItems.ts`, and `symbol.ts` (the `parentFqn` description);
  - `fakeReview.ts` uses `content.language`.
- **Contracts**, amended in place, before the code, in the same commit:
  - `common.schema.json` kinds;
  - the descriptions in `symbol.schema.json` `parentFqn`, `anchor.schema.json` `SymbolRef.language`, `lists.schema.json` `SymbolList`, and `search-hit.schema.json`;
  - `rest-api.md` V9 (languages, time budget, ETag) and V16 ("symbols");
  - the specs README schema row, ETag row, and amendment list;
  - `app:schema:bundle`.
- **Docs:**
  - `docs/requirements.md`: FR6 and the Non-goals row.
  - `docs/architecture.md`: the SymbolKind and SymbolRef rows, the Index section, the capture table (`symbol` row), the failure row F12, the services table, the test strategy, D11, D33, 7.1, and a section "Symbol languages" with the FQN table.
  - `docs/developer-guide.md`: runtime, `App\Index`, the ledger row "Symbol index", and a row in section 3.4, "A new symbol language". That row says a changed query or grammar needs `app:index:rebuild --reparse` and a V9 ETag bump.
  - `docs/user/reviewing.md`.
  - The verified-versions table in `docs/plan/README.md`.
  - `docs/backlog/more-languages.md`: it keeps only Python, Go, and Rust.

## Out of scope

- Python, Go, and Rust: F29.
- Go to declaration (V14) and Cmd-click for other languages: a candidate.
- An index version that re-parses stored blobs after a grammar or query change: a candidate. Until then, use `app:index:rebuild --reparse`.
- Concurrent requests to the service in the index job: a candidate, if the measured factor calls for it.
- Prototype assignments (`A.prototype.m = function`), object-literal methods, and declarations in function bodies.

## Acceptance tests

- **U** (Vitest, `symbols/`), with fixtures under `tests/Fixtures/<language>/`. Rows are `[kind, fqn, name, parentFqn, start, end, declarationStartLine, docStartLine]`, as in `PhpSymbolExtractorTest`.
  - The JavaScript, JSX, TypeScript, and TSX fixtures give their tables.
  - A syntax error gives `parseError` and no symbols.
  - A function in a function body is skipped.
  - A `/** */` before `export` and before decorators starts the range.
  - Overload signatures join the implementation.
  - Every emitted kind is in `SYMBOL_KINDS`.
- **U** `symbolMatch.test.ts`: short names from the FQN for PHP, TypeScript, and a macro.
- **U** `symbolItems.test.ts`:
  - `struct` and `module` head their groups;
  - a class in a namespace heads its own group.
- **U** `contract-enums.test.ts` and `EnumContractTest`: the four kinds, in order.
- **F** `TreeSitterSymbolExtractorTest`: a TypeScript round trip through the service, and a syntax error.
- **F** `SymbolsApiTest`:
  - a `.ts` file gives `language: typescript`, its symbols, and ETag `W/"v2-<blobSha>"`;
  - identical bytes at `a.js` and `a.ts` give two answers, each with its own language, and one `indexed_blob` row;
  - with `SYMBOLS_URL` set to a closed port, V9 of a `.ts` file gives 500 and no `indexed_blob` row, and V9 of a `.php` file gives 200.
- **F** `SymbolAnchorTest` and `LocatorSymbolAndTargetCasesTest`:
  - an exact selection of a TypeScript method becomes a symbol anchor;
  - it re-anchors by FQN after the method moves;
  - with the service down, Q3 gives 500 and writes no location-cache row.
- **F** `IndexReviewHandlerTest`: a commit with PHP and TypeScript files stores the symbols of both.
- **F** `DeclarationsTest`: a global PHP `class Foo` and a TypeScript `class Foo` in one commit give only the PHP declaration.
- **UI** `SymbolsMenu.spec.ts`: a TypeScript file lists its symbols, added through the `FakeReview` options as in `CodeNavigation.spec.ts`.

## Verification

- `make check` is green, and `doctrine:schema:validate` passes.
- `make up`: `docker compose ps` shows `symbols` healthy, without a host `npm ci`.
- The service answers directly: `curl` from the `php` container to `http://symbols:3000/v1/symbols?language=tsx` with a `.tsx` file of this repository.
- The index time is recorded in the report: `app:index:rebuild` on this repository, the time for `assets/**/*.ts(x)` against PHP files of similar size, and the factor of backlog criterion 5.
- Manual check:
  - open a `.tsx` file in a review: the Symbols menu lists its components;
  - an exact selection becomes a symbol anchor;
  - after a commit that moves the function, the comment follows it.
- Stop `symbols`:
  - a `.ts` file's Symbols menu says "Symbols could not be loaded", and PHP files still work;
  - the index job fails visibly;
  - after a restart, `app:index:rebuild` recovers it.
