# Symbol anchors for more languages

Status: backlog. The proof of concept extracts symbols for PHP only, with `nikic/php-parser`.

## Why

- Most PHP projects also contain JavaScript or TypeScript, templates, and configuration. Symbol anchors make comments on those files follow the code when it moves.
- `SymbolRef.language` and `SymbolKind` are open sets in the v1 contract, so new languages are additive.

## What

- Evaluate tree-sitter. The phase 1 research in [stack-findings.md](../architecture/stack-findings.md) found no maintained PHP binding. The two options are:
  - the tree-sitter CLI through `symfony/process`;
  - a small Node sidecar with `web-tree-sitter`.
- Check the current versions of the CLI, `web-tree-sitter`, and each grammar before the work starts. The phase 1 values are in the stack findings.
- Use the `tags.scm` definition queries of each grammar to find classes, functions, and methods, and map them to `SymbolKind`.
- Add an extractor interface with one implementation per language, `PhpSymbolExtractor` included, and let `BlobSymbols` pick the extractor through a tagged iterator. The index job keeps skipping blobs that are already indexed.
- Start with JavaScript and TypeScript.
- The symbol range rule stays: from the doc comment, if there is one, to the last line of the node.

## Acceptance criteria

1. Fixture files per language give the expected symbol tables, with FQNs and ranges.
2. The Symbols menu lists the symbols of a supported file, and an exact range selection becomes a symbol anchor.
3. A symbol anchor in a supported language re-anchors by FQN, as architecture section 5.4 describes for PHP.
4. A syntax error gives no symbol table and no error, as for PHP.
5. The index job stays within the runtime of the PHP extractor for files of the same size, within a factor that the evaluation records.

## Dependencies

- M26 symbol index and M27 symbol anchors.
