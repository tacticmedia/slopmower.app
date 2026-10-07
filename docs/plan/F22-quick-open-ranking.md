# F22 Quick open ranking

Workflow, conventions, and status: [feature track](features.md). From the F11 report.

## Goal

Quick open finds a symbol from a camel-case abbreviation, for example `CE` for `CommandExecutor`, and lists shorter names first within each group.

## Decisions

Jan, 2026-10-07: pick the Candidates that help most when reviewing this repository with Holistic Review, and decide what a sensible default decides.

- **Decision D54** amends the V16 rules of D48 in place, as D45 did. The wire format does not change: more names match, and the order changes.
- **Word starts.** The words of a name are split at every character that is not a letter or a digit, before an upper-case letter that follows a lower-case letter or a digit, and before an upper-case letter that follows an upper-case letter and precedes a lower-case letter. So `HTTPClient` has the words `HTTP` and `Client`. A name matches `q` by word starts when `q`, ignoring case, is a sequence of non-empty prefixes of its words, in word order, with words skipped where needed. `CE` and `ComEx` match `CommandExecutor`, `ReqDec` matches `RequestDecider`, `tax` matches `InvoiceTax`, and `ce` does not match `Service`. A `q` with a character that is not a letter or a digit matches no name by word starts.
- **Matching.** A file hit is a file whose path contains `q`, or whose name matches `q` by word starts. A symbol hit is a symbol whose name contains `q` or matches it by word starts. Case is ignored, as before.
- **Order:**
  1. by the name: equal to `q`; starting with `q`; matching `q` by word starts; containing `q`; then a file whose path contains `q` and whose name matches neither way;
  2. by the length of the name, shorter first;
  3. by the length of `path`, shorter first;
  4. by `path` in byte order;
  5. files before symbols, then by `declarationStartLine`, then by `fqn` in byte order.
- **Where the rule runs.** A word-start match holds the characters of `q` in order, so the SQL of `IndexSql::search()` selects the candidates with an escaped `LIKE` pattern over those characters, next to the path substring for files. PHP ranks the candidates and keeps the first `limit`. The match and the rank are pure static code in `App\Index`, without the kernel, so a unit test covers them. It is not a service.
- **Performance.** F11 measured 2 to 7 ms for the query on this repository (1,337 files, 4,792 symbols). Measure V16 again for `e`, `CE`, `index`, and `CommandExecutor` on the dev review of this repository, and record the times. The browser round trip stays under 100 ms there. If it does not, record the numbers and the cause in the report.

## Depends on

F11 (merged).

## In scope

- Specs: `docs/specs/rest-api.md` V16 rules (match and order), the specs README section 8.10 (D54), `docs/architecture.md` section 11 (D54) and section 8 flow 10.
- `src/Index/IndexSql.php` (`SEARCH` and `search()`), a pure class in `src/Index/` for the match and the rank.
- Docs: `docs/user/reviewing.md` section "Quick open" (abbreviations and the order), the developer guide counts.

## Out of scope

- Fuzzy matching other than word starts, or a typo tolerance.
- Match highlighting in the dialog.
- A new index or a migration.

## Acceptance tests

- **U:** the word-start match: `CE` and `ComEx` match `CommandExecutor`; `HC` matches `HTTPClient`; `ReqDec` matches `RequestDecider`; `tax` matches `InvoiceTax`; `ce` does not match `Service`; `C-E` matches nothing. The rank: one case per order rule that is new (word starts between prefix and substring, name length before path length).
- **F** (V16, extend the existing search test): `CE` lists `CommandExecutor` before a name that only contains `ce`; a shorter name comes before a longer name in a shorter path. The response validates against the schema.
- Existing V16 tests stay green, or change only where the new order rule 2 changes their order.

## Verification

- `make check` is green.
- Manual check on the dev app: Cmd+K, type `CE`; `CommandExecutor` is among the first hits. Record the measured times.
