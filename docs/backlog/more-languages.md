# Symbol anchors for Python, Go, and Rust

Status: backlog, scheduled as [F29](../plan/F29-symbols-for-python-go-rust.md). PHP, JavaScript, JSX, TypeScript, and TSX have symbols. Architecture section 4, [Symbol languages](../architecture.md#symbol-languages), describes the engine.

## Why

- Repositories mix languages. Symbol anchors make comments on Python, Go, and Rust code follow the code when it moves.
- `SymbolRef.language` and `SymbolKind` are open sets in the v1 contract, so new languages are additive.

## What

- Per language: a grammar package in `symbols/package.json`, a query `symbols/queries/<language>.scm`, a row in `symbols/src/languages.ts`, its id in `TreeSitterSymbolExtractor::LANGUAGES`, and a fixture test. `FileLanguage` already maps `py`, `go`, and `rs`.
- Check the current versions of the grammars, and that web-tree-sitter loads their wasm files, before the work starts.
- Go and Rust declare methods outside their type: a Go receiver and a Rust `impl` name the parent, so the queries need a capture for it.

## Acceptance criteria

1. Fixture files per language give the expected symbol tables, with FQNs and ranges.
2. The Symbols menu lists the symbols of a supported file, and an exact range selection becomes a symbol anchor.
3. A symbol anchor in a supported language re-anchors by FQN, as architecture section 5.4 describes.
4. A syntax error gives `parseError: true`, no symbols, and no error.

## Dependencies

- The symbols service of D56.
