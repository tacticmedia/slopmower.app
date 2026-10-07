# F26 Blame view

Workflow, conventions, and status: [feature track](features.md). Jan's request of 2026-10-08, split from [F25](F25-github-style-code-browser.md): a blame view as on GitHub, after the code browser redesign.

## Goal

The file toolbar has a "Code | Blame" switch, as on GitHub. "Blame" shows, beside the code, the commit that last changed each group of lines up to the review commit: the age, the date, the author, and the message.

## GitHub reference

Measured on `symfony/symfony` `blame/8.2/src/Symfony/Component/HttpFoundation/Request.php` at 1440x900, signed out, with the `chrome-devtools` MCP server:

- **Route:** `/blame/<ref>/<path>`, next to `/blob/<ref>/<path>`. The toolbar has a segmented control "Code | Blame", and the rest of the file view stays: breadcrumbs, the sticky toolbar with the stuck row, Raw, copy, download.
- **Banner:** "Ignoring revisions in .git-blame-ignore-revs." when the repository has that file. GitHub applies it.
- **Legend row** below the toolbar, sticky with it: "Older", ten swatches of `--display-orange-scale-0` to `-9`, "Newer"; at the right the contributors of the file.
- **Hunks:** one block per run of lines from one commit, with a border between blocks across the whole width. The commit column is 350 px: a 4 px bar in the age colour along the block, the date (`relative-time`, "17 years ago", with the full date as title), the author avatar, the commit summary as a link to the commit, and a "Blame prior to change <sha>, made on <date>" button that opens the blame of the parent commit at the path the file had there. The line numbers and the code follow. A one-line block is 30 px high; a longer block has 20 px per line. A long block's commit column scrolls away with the block.
- **Age colours:** by the age of the commit against now: scale 9 for a day, 6 for about a month, 5 for two months, 3 for five months, 2 for seven months, 1 for about a year, 0 for older (207 blocks sampled).
- **Scroll:** the window scrolls, and the rows are virtualized against it (276 rows rendered of 2,343).

## Decisions

1. **V17 `GET /api/v1/reviews/{reviewId}/blame?path=`** (D59). It returns the blame of a file at the review commit, from `git blame --incremental` with the review commit as the revision. Schema `Blame`:
   - `reviewId`, `commitSha`, `path`, `blobSha`;
   - `ignoreRevsFile`: true when `.git-blame-ignore-revs` exists at the review commit and has at least one full commit SHA;
   - `ranges`: runs of lines with one commit, ascending, without gaps, from line 1 to the last line: `{startLine, endLine, commitSha}`. Adjacent groups of one commit are merged;
   - `commits`: each commit of `ranges` once: `{sha, authorName, authoredAt, summary}`. `authorName` is the name that the commit records, without a mailmap: a bare clone reads `HEAD:.mailmap`, and a fetch moves `HEAD`, so the cached answer would change. `-c mailmap.blob= -c mailmap.file=` turns it off, as `commitDetails` uses `--no-mailmap`.
2. **Errors as V7:** 404 `not_found` for a path that the review commit lacks; 422 `invalid_input` for a directory, a submodule, a symlink, a binary file, or a file above the 1 MiB limit of V7 (`renderable` false); 409 `repository_unavailable`.
3. **Caching as `/file` and `/symbols`:** ETag `W/"v1-<commitSha>-<blobSha>"`, `Cache-Control: private, no-cache`, 304 on a match. The ETag comes from the review and the tree entry, so a 304 runs no blame.
4. **`.git-blame-ignore-revs`:** read at the review commit. Only lines that are a full commit SHA are kept, because git refuses any other text in the file; git accepts SHAs of the file that the clone lacks, but `--ignore-rev` refuses them. The kept lines go to git on standard input with `--ignore-revs-file /dev/stdin`, so no temporary file exists.
5. **Process:** `GitRunner::run()` with `blame --incremental [--ignore-revs-file /dev/stdin] --end-of-options <commit> <path>`. `--incremental` reports each group once, without the file content. git blame takes `--` after `--end-of-options` as a revision, so the path follows the revision without `--`. No repository lock, as for the other reads.
6. **Code:** `GitReader::blame()` runs and parses git; `App\Git\BlameRange` and `App\Git\BlameCommit` are schema-shaped, as `App\Index\ExtractedSymbol` of V9; the response `App\Application\Query\Blame` is schema-shaped; the action `CodeController::getBlame()` (`api_review_blame`) reuses `FileQuery::entry()`, the V7 ETag pattern, and reads the ignore file in a private method, because it has one caller.
7. **REST only.** No MCP tool: an agent works in a checkout and runs git itself.
8. **Front end:**
   - Route `/reviews/:reviewId/blame/*`, with the loader of the file route plus V17. The toolbar gets a Primer `SegmentedControl` "Code | Blame" that switches between `files/*` and `blame/*` and keeps the line hash.
   - The blame view is the code view without threads, without the gutter "+", and without line wrap, so every row has the line height of the view. A blame column of 350 px at the left of the code places one block per range from the line positions of `VirtualizedFile` (`getLinePosition()`); it renders only the blocks near the window. Line selection, the line hash, Cmd-click, and the scope lines stay.
   - A block: a 4 px bar in `--display-orange-scale-<n>`, the date as Primer `RelativeTime`, the author, and the summary, with the full date, the author, the summary, and the short SHA as its title. Blocks are separated by a border in the column.
   - Age scale by the days since `authoredAt`: under 7 is 9, under 14 is 8, under 21 is 7, under 45 is 6, under 90 is 5, under 120 is 4, under 180 is 3, under 270 is 2, under 450 is 1, else 0.
   - Below the toolbar in the blame view: the legend "Older", ten swatches, "Newer", and the banner "Ignoring revisions in .git-blame-ignore-revs." when `ignoreRevsFile` is true.
   - The file commands that open a comment are hidden in the blame view: "Comment on lines", "Symbols", "Comment on file". "Go to declaration", "Viewed", and the raw actions stay.
9. **Differences from GitHub:** no avatars, because a local repository has none; no link on the summary, because the review has no commit page; no "Blame prior to change", because the review pins one commit and the view would leave it; no contributors; every row is 20 px, also in a one-line block.

## Depends on

[F25](F25-github-style-code-browser.md) (merged): the toolbar, the window-scroll code view, the sticky header.

## In scope

- Specs: `docs/specs/rest-api.md` (V17 and the endpoint index), `docs/specs/schemas/blame.schema.json`, `docs/specs/fixtures/blame.php.json`, `docs/specs/README.md` (schema list, caching row, amendment list), architecture section 11 D59.
- Back end: the blame read in `App\Git`, the V17 action next to V7 and V8, its response class in `App\Application\Query`.
- Front end: the `blame/*` route and loader, the "Code | Blame" switch, the blame column, the legend and the banner, the age scale, the API type.
- Tests and docs: below.

## Out of scope

"Blame prior to change", avatars, commit links, contributors: [Candidates](features.md#candidates). A server-side cache of the blame. MCP.

## Acceptance tests

- **F `tests/Functional/Api/BlameTest.php`** (a real git repository through `LocalSources`, three commits by two authors with fixed dates, one rename; `GitRepositoryBuilder::commit()` gets an optional author and date): the ranges and commits of a file, with adjacent groups of one commit merged; `.git-blame-ignore-revs` with a comment, an unknown SHA, and the SHA of the second commit moves that commit's lines to the first and sets `ignoreRevsFile`; 404 for a missing path; 422 for a directory and for a binary file; a 304 for the ETag; the response validates against `Blame`.
- **F cross-tenant:** the case `api_review_blame` in `CrossTenantCases`, which `CrossTenantCoverageTest` requires.
- **Contract:** the README fixture table lists `blame.php.json` (`ContractFixturesTest`), `SchemaCoverageTest` finds `blame.schema.json` and its `$defs` named in a test, and `contract-fixtures-type.test.ts` checks the fixture against `assets/api/types/blame.ts`.
- **V** `blameAge.test.ts`: the age scale at its bounds. The fixture `blame.php.json` type-checks against `assets/api/types/blame.ts`.
- **UI `BlameView.spec.ts`:**
  - "Blame" in the toolbar opens `blame/<path>` with the hash kept, and "Code" returns;
  - each range has one block beside its first line, with the date, the author, and the summary, and the block bottom aligns with its last line;
  - a block far down the file aligns after `#L3000`, and fewer than 300 rows render;
  - the age bar uses the scale of the commit date;
  - the legend shows, and the banner shows only with `ignoreRevsFile`;
  - the comment commands are hidden, and no gutter "+" shows.

## Verification

- `make check` is green.
- Manual check in the dev stack against the GitHub reference: the blame of a long file of this repository, scrolled far down; the blocks align with the lines at 1280x720 and 1920x1080.
