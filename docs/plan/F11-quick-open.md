# F11 Quick open

Workflow, conventions, and status: [feature track](features.md).

## Goal

The reviewer finds a file or a symbol of the review commit by typing part of its name and opens it. This is the counterpart of F04's code navigation for names that are not on screen.

## Decisions

- **Endpoint.** A new additive endpoint, `GET /api/v1/reviews/{reviewId}/search?q=&limit=`, returns up to `limit` hits, at most 50.
  - It reads two kinds of hits from the review commit: files from `indexed_file` paths, and PHP symbols from the symbol index joined through `indexed_file` for that commit.
  - A file hit is `{kind: "file", path}`. A symbol hit is `{kind: "symbol", path, fqn, symbolKind, declarationStartLine}`.
  - Matching is case-insensitive on a substring of the path or the symbol name. Ranking: exact name, then prefix, then substring, then shorter path.
- **No new index.** The query starts from the commit's `indexed_file` rows and the symbol index by blob. At this repository's size, about 1,300 files and 4,700 symbols per commit, that is fast enough. An index on names would hit the same B-tree row-size limit as F04's FQNs. Record the measured time in the report.
- **Index state.** If the review index is not ready, the endpoint answers from what is indexed and says so in a `partial: true` field. The UI shows "Index in progress".
- **UI.** A "Quick open" button in the review header opens a dialog with a search field and a result list. The keyboard shortcut is Cmd+K on macOS and Ctrl+K elsewhere.
  - Choosing a file opens it. Choosing a symbol opens its file at `#L<declarationStartLine>` with that line selected, as F04 does.
  - The whole dialog works by keyboard: arrow keys, Enter, and Escape.
- **MCP.** No MCP tool. Agents search the code themselves.

## Depends on

M26, F04. An additive contract change: a new endpoint, a new schema, and a fixture.

## In scope

- The spec (the endpoint, the schema, a fixture, and 422 for an empty or overlong `q`), the endpoint and its query, a cross-tenant case and inventory entries, the dialog with its shortcut, and the docs: user docs, the developer guide, and the architecture endpoint list.

## Out of scope

- Full-text search in file contents.
- Fuzzy matching beyond substring.
- Languages other than PHP for symbols.

## Acceptance tests

- **F:**
  - A file and a symbol hit for one query, schema-valid, in rank order.
  - `partial: true` while the index is not ready.
  - 422 for an empty `q`.
  - The cross-tenant case.
- **UI:**
  - Cmd+K opens the dialog. Typing and Enter open a file, and a symbol opens at its declaration line.
  - The keyboard-only path works.

## Verification

`make check`. Then a manual check on the dev app: press Cmd+K, type `CommandExecutor`, and press Enter; the declaration line opens.
