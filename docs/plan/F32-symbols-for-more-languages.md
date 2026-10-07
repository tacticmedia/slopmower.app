# F32 Symbols for Java, C#, Ruby, Bash, Scala, Zig, and Lua

Workflow, conventions, and status: [feature track](features.md). Jan scheduled on 2026-10-08 the languages that the candidate "More symbol languages" lists as data only. [F27](F27-symbols-through-tree-sitter.md) built the engine, and [F29](F29-symbols-for-python-go-rust.md) added three languages the same way.

## Goal

The Symbols menu, exact-range symbol anchors, re-anchoring by FQN, quick open, and the F25 sticky scope lines work for `.java`, `.cs`, `.rb`, `.sh`, `.bash`, `.scala`, `.zig`, and `.lua` files.

## Facts

- A language takes:
  - a grammar package in `symbols/package.json`;
  - `symbols/queries/<language>.scm`;
  - a row in `symbols/src/languages.ts`;
  - its id in `TreeSitterSymbolExtractor::LANGUAGES`;
  - a fixture test.

  The row holds the separator, wrapper, attribute, and opaque node types, the doc comment pattern, and `docCommentAdjacent`. Queries can capture `@parent` and `@as` (F29).
- Checked on 2026-10-08 with web-tree-sitter 0.27.0 on Node 24.12. Each npm grammar ships a `.wasm` that loads and parses a sample without errors:

| Language | Package | ABI | Upstream `tags.scm` |
|---|---|---|---|
| Java | `tree-sitter-java` 0.23.5 | 14 | yes |
| C# | `tree-sitter-c-sharp` 0.23.5, file `tree-sitter-c_sharp.wasm` | 15 | yes |
| Ruby | `tree-sitter-ruby` 0.23.1 | 14 | yes |
| Bash | `tree-sitter-bash` 0.25.1 | 15 | no |
| Scala | `tree-sitter-scala` 0.24.0 | 14 | yes |
| Zig | `@tree-sitter-grammars/tree-sitter-zig` 1.1.2 | 14 | no |
| Lua | `@tree-sitter-grammars/tree-sitter-lua` 0.4.1 | 15 | yes |

- A plain `npm install` of many grammars failed with `ERESOLVE`. The grammars declare peer dependencies on different versions of the native `tree-sitter` binding, which the service does not load. `--legacy-peer-deps` installed them.
- `FileLanguage` maps:
  - already: `java` to `java`, `cs` to `csharp`, `rb` to `ruby`, `sh` and `bash` to `shellscript`;
  - not yet: `scala`, `zig`, `lua`.
- The `macro` suffix `!` holds for every language. No language of F32 needs the `macro` kind.
- In tree-sitter-c-sharp, the declarations after a file-scoped `namespace A;` are siblings of the namespace node in `compilation_unit`, not its children.

## Decisions

D57 holds. Its separator rule now reads "`.`, or `::` for Rust and Ruby".

| Language (V7 id) | Kinds and FQN examples | Doc comment |
|---|---|---|
| Java (`java`) | class `A` (also record), interface `I` (also annotation type), enum `E` with enum_case `E.A`; method `A.m()`, also a constructor `A.A()`; field as property `A.x`; nested type `A.B`. The package is not part of the FQN. | `/** */` |
| C# (`csharp`) | class `A` (also record), interface `I`, struct `S` (also record struct), enum `E` with enum_case `E.A`, delegate as type `D`; method `A.M()`, also a constructor; property, field, and event as property `A.P`; nested type `A.B`. A namespace, block or file-scoped, is no symbol and not part of the FQN, as a Java package. | adjacent `///` block |
| Ruby (`ruby`), separator `::` | module `M`; class `M::A`; method `M::A::m()`, also `def self.m`; top-level `def` as function `f()`; constant `M::X` | adjacent `#` block |
| Bash (`shellscript`) | function `f()`, both `f() { }` and `function f { }` | adjacent `#` block |
| Scala (`scala`) | class `A` (also case class), trait `T`, object as module `O`, enum `E` with enum_case `E.A`; method `A.m()`; top-level `def` as function `f()`; `val` or `var` member as property `A.x`; type alias as type `T` | `/** */` |
| Zig (`zig`) | function `f()`; `const S = struct { }` as struct `S` (also unions), enum `E` with enum_case `E.A`; `fn` in a container as method `S.m()`; container field as property `S.x`; another top-level `const` as constant `C` | adjacent `///` block |
| Lua (`lua`) | function `f()`, also `local function`; `function M.f()` as function `M.f()` with `parentFqn` `M`; `function M:m()` as method `M.m()` with `parentFqn` `M` | adjacent `---` block |

- Declarations in function and method bodies are skipped, as in every language.
- Annotations and attributes that the grammar puts inside the declaration node are in its range. Annotations that it puts before the node are attribute node types of the row.
- If the symbols package needs it, `symbols/.npmrc` sets `legacy-peer-deps=true`, and the Dockerfile stage copies it.
- The V9 ETag goes to `v4`.

## Depends on

F27, F29.

## In scope

- A spike, recorded in the report: check the latest npm versions, and that the seven wasm files load.
- `symbols/package.json`, its lock file, and `symbols/.npmrc` if needed; seven queries, seven language rows, and seven fixtures with their Vitest cases.
- `FileLanguage`: `scala`, `zig`, and `lua`. `TreeSitterSymbolExtractor::LANGUAGES`, and `ETAG_VERSION = 'v4'`.
- `tests/ui/support/fakeReview.ts`: `SYMBOL_LANGUAGES`.
- Docs:
  - D57 in architecture section 11, and the FQN table of "Symbol languages";
  - `docs/requirements.md` FR6;
  - `docs/user/reviewing.md`;
  - `docs/developer-guide.md` where it lists the languages;
  - the specs: V9 language list, the ETag row, and the D56/D57 amendment line;
  - the verified-versions table in `docs/plan/README.md`.

## Out of scope

- C, C++, Elixir, and the other languages of the candidate "More symbol languages".
- Twig: a separate candidate, with the Twig lexer in PHP.
- Any change to `BlobSymbols`, `IndexSql`, the schemas, or the front end. If one is needed, the report records why.

## Acceptance tests

- **U** (Vitest, `symbols/`), with fixtures under `tests/Fixtures/<language>/`, each giving its table:
  - Java: a record, an annotation on a method, a nested class, an enum with constants, and a constructor.
  - C#: a file-scoped namespace, a block namespace, a property, an attribute, and a `///` block.
  - Ruby: nested `module` and `class`, `def self.m`, and a constant.
  - Bash: both function forms.
  - Scala: an object, a case class, a trait, and a top-level `def`.
  - Zig: a struct with fields and methods, an enum, and a `///` block.
  - Lua: `function M.f()`, `function M:m()`, and a `local function`.
  - A syntax error in each language gives `parseError` and no symbols.
- **F** `SymbolsApiTest`: one file per language gives its language and symbols, and the ETag `W/"v4-<blobSha>"`.

## Verification

- `make check` is green.
- `curl` from the `php` container to `http://symbols:3000/v1/symbols?language=<id>` gives the expected FQNs for one sample per language.
