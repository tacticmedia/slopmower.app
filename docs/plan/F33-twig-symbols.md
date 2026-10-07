# F33 Twig symbols

Workflow, conventions, and status: [feature track](features.md). Jan scheduled on 2026-10-08 the candidate "Twig symbols", with a new symbol kind `block`. Twig is parsed in PHP with the Twig lexer, as PHP is parsed with `nikic/php-parser`. The `symbols` service of D56 is not involved.

## Goal

The Symbols menu, exact-range symbol anchors, re-anchoring by FQN, quick open, and the F25 sticky scope lines work for `.twig` files, with their blocks and macros.

## Facts

- `twig/twig` 3.30.0 and `symfony/twig-bundle` are installed (`composer.json`: `twig/twig` `^2.12 || ^3.0`). `FileLanguage` maps `twig` to the V7 language `twig`.
- `Environment::parse()` needs the filters, functions, and tags of every extension that a template uses. A probe on 2026-10-08 failed with `Unknown "money_format" filter.` for a template with a filter of its own.
- `Environment::tokenize(Source)` needs none.
  - An `Environment` with an `ArrayLoader` and no extensions returned each tag with its line: `extends@1 block@2 block@3 custom_tag@5 endblock@6 macro@7 endmacro@9`.
  - `Token` has `getType()`, `getValue()`, `getLine()`, `getOffset()`, and `getDocumentation()`.
  - A lexer error throws `Twig\Error\SyntaxError`.
- Twig 3.30 has documentation comments `{## ... #}` (experimental in its CHANGELOG). The lexer attaches their text to the next token, here the `{%` of a tag. A plain `{# #}` comment is dropped.
- `BlockTokenParser`:
  - a block is `{% block name %} ... {% endblock %}`, and `{% endblock name %}` may repeat the name;
  - `{% block name expression %}` is a short block without `endblock`.
- A macro is `{% macro name(arguments) %} ... {% endmacro %}`, and `{% endmacro name %}` may repeat the name.
- Twig compiles a block into a method and a macro into a method. Block names are unique in a template, and nesting does not qualify them.
- Blocks inside `{% embed %} ... {% endembed %}` override the blocks of the embedded template, and their names may repeat the names of the outer template.
- `SymbolExtractor` (F27): `BlobSymbols` picks the extractor whose `languages()` contain the `FileLanguage` of the path.
- `SymbolKind` is decoded strictly by `SymbolKind::from()` in `IndexSql` and by `PayloadReader::enum()`. `EnumContractTest` and `assets/api/contract-enums.test.ts` compare the order of the kinds.
- The F25 sticky scope lines use `SCOPE_KINDS` in `assets/code/symbolMatch.ts`: the containers plus `function` and `method`.

## Decisions

D60 in [architecture section 11](../architecture.md#11-decisions).

- `src/Index/TwigSymbolExtractor.php` implements `SymbolExtractor` for `['twig']`.
  - It tokenizes with an `Environment` of its own, built with an `ArrayLoader` and no extensions. The application's `twig` service is not used, so the extensions of this application do not decide what a reviewed template gives.
  - It never calls `parse()`.
- **Kinds:** a new `SymbolKind` value `block`, appended after `macro` in `SymbolKind.php`, `common.schema.json`, and `common.ts`. A macro is a `function`.
- **FQNs:**
  - block `content`;
  - macro `row()`;
  - `parentFqn` is null, because Twig block names are flat.
- **Ranges:**
  - `declarationStartLine` is the line of the opening `{%`.
  - The range ends at the line of the closing `%}` of `endblock` or `endmacro`. A short block ends at the `%}` of its own tag.
  - `docStartLine` is the line of the first `{##` of the documentation comments right before the tag, if the `{%` token has documentation. Otherwise it is null. D7 holds.
- **Not symbols:** blocks inside `embed` are skipped. A macro inside a block cannot occur in Twig.
- **Parse errors:** a lexer error, an `endblock` or `endmacro` without its opening tag, a name after `endblock` or `endmacro` that differs from the opening name, or an opening tag without its end give `parseError: true` and no symbols. Unknown tags, filters, and functions are no error.
- **Front end:** `SCOPE_KINDS` gets `block`, so the sticky scope lines show the enclosing blocks. A block does not head a group in the Symbols menu.
- **ETag:** the V9 ETag gets the next version after F32.
- **Rollback:** no rollback is possible once a row or an anchor event has the kind `block`.

## Depends on

F27. F32 merges first.

## In scope

- `src/Index/TwigSymbolExtractor.php`, `SymbolKind`, `SymbolController::ETAG_VERSION`.
- `common.schema.json`, `assets/api/types/common.ts`, `assets/code/symbolMatch.ts` (`SCOPE_KINDS`), `tests/ui/support/fakeReview.ts` (`SYMBOL_LANGUAGES`), and `src/Mcp/BundledSchemas.php` (`app:schema:bundle`).
- Docs:
  - D60 in architecture section 11 (the orchestrator adds the row before the work);
  - the "Symbol languages" section and its table;
  - `docs/requirements.md` FR6;
  - `docs/user/reviewing.md`;
  - `docs/developer-guide.md` (`App\Index`, and the section 3.4 row "A new symbol language": a language with a parser in PHP gets a `SymbolExtractor` of its own);
  - the specs: the `SymbolKind` enum, the V9 language list, the ETag row, and the amendment list with D60.

## Out of scope

- Go to declaration for Twig: `extends`, `include`, `embed`, `use`, and `block()` references.
- `{% use %}` and `{% set %}`.
- Plain `{# #}` comments as doc comments.

## Acceptance tests

- **U** `TwigSymbolExtractorTest` (`KernelTestCase`), with a fixture under `tests/Fixtures/twig/`, giving rows of `[kind, fqn, name, parentFqn, start, end, declarationStartLine, docStartLine]`:
  - a long block with a nested block, and `{% endblock name %}`;
  - a short block;
  - a macro with arguments;
  - a block inside `embed`, which is skipped;
  - a `{## ... #}` documentation comment before a block, which starts its range;
  - an unknown filter and an unknown tag, which give no error.
- **U** the same test: an unclosed `{%`, an `endblock` without a block, and a mismatched `endblock name` each give `parseError` and no symbols.
- **U** `EnumContractTest` and `contract-enums.test.ts` with `block`. Vitest `EnclosingScopesTest`: a line inside a nested block gives both blocks, outermost first.
- **F** `SymbolsApiTest`: a `.twig` file gives `language: twig`, its symbols, and the new ETag.

## Verification

- `make check` is green.
- Manual check: in a review of this repository, `templates/` files list their blocks in the Symbols menu, and an exact selection of a block becomes a symbol anchor.
