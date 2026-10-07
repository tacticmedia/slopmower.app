# F29 Symbols for Python, Go, and Rust

Workflow, conventions, and status: [feature track](features.md). The second half of Jan's request of 2026-10-08. [F27](F27-symbols-through-tree-sitter.md) builds the engine. F29 adds three languages and shows that a language is data.

## Goal

The Symbols menu, exact-range symbol anchors, re-anchoring by FQN, quick open, and the F25 sticky scope lines work for `.py`, `.go`, and `.rs` files.

## Facts

- After F27, a language takes a grammar package in `symbols/package.json`, `symbols/queries/<language>.scm`, a row in `symbols/src/languages.ts`, its id in `TreeSitterSymbolExtractor::LANGUAGES`, and a fixture test.
- `FileLanguage` already maps `py` to `python`, `go` to `go`, and `rs` to `rust`.
- On npm on 2026-10-08:
  - tree-sitter-python 0.25.0;
  - tree-sitter-go 0.25.0;
  - tree-sitter-rust 0.24.0 (GitHub has 0.24.2).

  Each ships a `.wasm` and a `queries/tags.scm`. The upstream Python `tags.scm` has no methods. The Go `tags.scm` uses `#set-adjacent!`, which neither the tags library nor web-tree-sitter applies.
- A Go method is declared outside its type, and the type can be in another file of the package. A Rust method is declared in an `impl` block, and the trait of `impl Trait for Type` is part of its identity.

## Decisions

D57 in [architecture section 11](../architecture.md#11-decisions), rows for these languages:

| Language | Kinds and FQN examples | Doc comment |
|---|---|---|
| Python | class `A`, method `A.m()`, function `f()`, nested class `A.B`. `declarationStartLine` is the first decorator. | none: the docstring is inside the range |
| Go | function `F()`, method `T.M()` with `parentFqn` `T` from the receiver, without pointer or type parameters, also when `T` is in another file; struct `T` with property `T.F`; interface `I` with method `I.M()`; other types as type `T`; top-level constant `C` | the adjacent `//` block |
| Rust, separator `::` | struct `S` (also unions) with property `S::f`; enum `E` with enum_case `E::V`; trait `Tr` with method `Tr::m()`; inherent method `S::m()`; trait-impl method `<S as Tr>::m()` with `parentFqn` `S`; function `f()`; module `a`, giving `a::b::f()`; macro `m!`; constant (`const`, `static`); type | adjacent `///` or `/** */`; `#[...]` is an attribute, so `declarationStartLine` includes it |

- An explicit `@parent` capture names the parent of a Go method and of a Rust `impl` member. Without it, the parent is the nearest enclosing container, as in F27.
- The V9 ETag goes to `v3`.

## Depends on

F27.

## In scope

- A spike: check the latest npm versions, and that the three wasm files load. Record it in the report.
- `symbols/package.json`, `queries/python.scm`, `queries/go.scm`, `queries/rust.scm`, the language rows, and the `@parent` capture in `symbols/src/extract.ts`.
- `TreeSitterSymbolExtractor::LANGUAGES` and the V9 ETag version.
- Docs:
  - the FQN table in architecture "Symbol languages";
  - `docs/user/reviewing.md`;
  - the verified-versions table in `docs/plan/README.md`;
  - the specs V9 language list;
  - delete `docs/backlog/more-languages.md` and its row in `docs/backlog/README.md`.

## Out of scope

- Python module-level variables and Go package-level variables.
- Go to declaration for these languages.
- Any change to `BlobSymbols`, `IndexSql`, the schemas, or the front end. If one turns out to be needed, F27 missed it, and the report records it.

## Acceptance tests

- **U** (Vitest, `symbols/`), with fixtures under `tests/Fixtures/<language>/`, each giving its table:
  - Python: decorators, nested classes, and a function in a function, which is skipped.
  - Go: a method whose receiver type is in another file, a pointer receiver, a generic receiver, and a doc comment block.
  - Rust: an inherent `impl`, two `impl From<X> for S` with distinct FQNs, nested modules, `macro_rules!`, and attributes between a doc comment and an item.
  - A syntax error in each language gives `parseError` and no symbols.
- **F** `SymbolsApiTest`: a `.py`, a `.go`, and a `.rs` file each give their language and symbols, and the ETag `W/"v3-<blobSha>"`.

## Verification

- `make check` is green.
- Manual check: in a review of a repository with Go or Rust code, the Symbols menu groups methods under their type, and an exact selection of a method becomes a symbol anchor.
