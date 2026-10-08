# F35 Top-level group label

Workflow, conventions, and status: [feature track](features.md). Jan scheduled on 2026-10-08 the candidate "Top-level group label".

## Goal

The Symbols menu heads the group of the symbols without a container "Top level", a name that fits every language.

## Facts

- `assets/review/SymbolsMenu.tsx` titles the group `TOP_LEVEL_GROUP` "Functions and constants".
- Since F27, F29, F32, and F33, the group also holds TypeScript types, Go and Rust types, Bash functions, and Twig blocks and macros.
- `tests/ui/SymbolsMenu.spec.ts` names the group in one assertion. The doc comment of `TOP_LEVEL_GROUP` in `assets/review/symbolItems.ts` describes the group as functions and constants.

## Decisions

- The heading is "Top level".

## Depends on

F27.

## In scope

- `SymbolsMenu.tsx`, the doc comment of `TOP_LEVEL_GROUP`, and `SymbolsMenu.spec.ts`.

## Out of scope

- Other headings of the menu, and the grouping rule.

## Acceptance tests

- **UI** `SymbolsMenu.spec.ts`: the group of a top-level function is named "Top level".

## Verification

- `make check` is green.
