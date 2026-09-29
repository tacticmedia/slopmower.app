# M27 Symbol anchors and keyboard access

Workflow, conventions, and status: [README](README.md).

## Goal

Comment on a symbol from the menu or by selecting its exact range, also fully by keyboard. Decisions D7 and D31.

## Depends on

M26, M21.

## In scope

- Symbol anchors in `AnchorFactory`, for Q2 and D2:
  - the input is `kind: "symbol"` with `symbol.fqn`. The FQN must match exactly one symbol of the file;
  - the symbol's range, including the doc comment, becomes `anchor.range`, decision D7;
  - an unknown or ambiguous FQN gives 422 `invalid_input`. The file must be renderable.
- Exact ranges become symbol anchors in the client. docs/specs/rest-api-requests.md Q2 defines only explicit symbol anchors, so `assets/code/symbolMatch.ts` does the match:
  - a selection equal to a symbol's `range`, or to `declarationStartLine` through `range.endLine`, matches that symbol;
  - the comment box header then names the symbol, and the client sends `kind: "symbol"` with the FQN;
  - a selection that is one line off stays a range.
- A "Symbols" Primer `ActionMenu` in the file header:
  - it loads the symbols of the file from V9, grouped by parent and filterable;
  - it works with the keyboard;
  - it is disabled while the symbols load and when the file has none;
  - choosing a symbol selects its range through `selectedLines` and opens the comment box below the last line, anchored to the FQN.
- Keyboard access: check again that the focus behaviour of the `@pierre/diffs` "+" button equals the result that M21 recorded. If it changed, update the record in docs/architecture.md section 8 and report it.

## Out of scope

- Other languages: [docs/backlog/more-languages.md](../backlog/more-languages.md).

## Files (representative)

- `src/Application/AnchorFactory.php`.
- `assets/review/SymbolsMenu.tsx`, `assets/code/symbolMatch.ts`, `assets/code/symbolMatch.test.ts`.
- `tests/Functional/Api/SymbolAnchorTest.php`, `tests/E2E/KeyboardOnlyCommentingTest.php`, `tests/E2E/ExactRangeBecomesSymbolTest.php`.

## Tasks (ordered)

1. Add the symbol rule to `AnchorFactory`.
2. Write `symbolMatch.ts` and use it in the comment flow.
3. Build the Symbols menu.
4. Write the keyboard E2E test last.

## Acceptance tests

- **V `SymbolMatchTest`** proves the exact-range rule.
  - "A selection of the range with the doc comment matches the symbol"
  - "A selection from the declaration line to the end matches the symbol"
  - "A selection one line off stays a range"
- **F `SymbolAnchorTest`** proves symbol anchors on the server.
  - "A symbol anchor stores the range with the doc comment and the symbol reference"
  - "An unknown FQN gives 422 invalid_input"
  - "A draft with a symbol anchor works"
- **E `KeyboardOnlyCommentingTest`** proves keyboard-only commenting. It uses no mouse action.
  - "The Symbols menu opens, filters, and anchors a comment to a method with the keyboard only"
  - "Comment on lines and the file comment button work with the keyboard only"
  - "The focus behaviour of the gutter + button equals the recorded result"
- **E `ExactRangeBecomesSymbolTest`** proves the exact-range flow in the browser.
  - "Selecting exactly a method's range creates a symbol anchor with its FQN"

## Verification commands

```sh
make check
```

## Done

A comment on a method anchors to its FQN, through the menu, an exact selection, or the keyboard alone.
