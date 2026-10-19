# F64 Known issues of F57 to F60

Workflow, conventions, and status: [feature track](features.md). On 2026-10-19, Jan asked for an onboarding track (F66 to F71). The hard rule "bugs and issues before features" puts the open UI follow-ups of F57 to F60 first.

## Goal

The UI defects that the F57 to F60 reports and reviews found are fixed before the onboarding track changes the same screens.

## Facts

Verified on 2026-10-19:

- `reports/F57.md:52`: at 390 px and 768 px, the review table of the repository page and the token table of Settings overflow to the right. No row tracked it.
- `reports/F60.md:43`: "Connect your agent" in the Agent prompts overlay has the muted label style of `CopyField.module.css`. `assets/review/AgentPromptSnippet.tsx:18` gives the link `className={styles.label}`. No row tracked it.
- Candidate "Request detail sidebar polish" (F59 merge review):
  - the Location section shows the anchor path twice: the `AnchorLink`, then the location of `LocationInfo`;
  - the state line of `RequestDetail.tsx:48` says "0 comments". `RequestRow.tsx:52` shows the count only when it is not zero.
- Candidate "Markdown in summaries" (F58 report): `RequestSummaryNormalizer::summary()` (line 83) takes the raw first non-blank line of the body. The list, the h1, the thread header, the browser title, and MCP `get_request` show backticks and other syntax.
  - `request-summary.schema.json:59` defines `summary` as "The title when set, else the first non-blank line of body, trimmed".
  - `CheckRunOutput::headline()` (`src/Domain/Request/CheckRunOutput.php:199`) builds its own headline. It is domain code (D44), and GitHub renders the check summary as Markdown.

## Decisions

- **Tables:** each `DataTable` of the two pages scrolls horizontally inside its `Table.Container` below its natural width. The document never scrolls horizontally.
- **Link:** a default Primer `Link`, not the muted label style.
- **Sidebar:** Location shows the path and lines once, through `AnchorLink`. `LocationInfo` shows only the status and its explanation.
- **Comment count:** the state line leaves out the count when it is 0.
- **Summary:** the plain text of the first non-blank line.
  - The normalizer renders that line with league/commonmark, strips the tags, decodes the entities, trims, and then cuts to 200 characters.
  - The title, when set, stays as it is.
  - `CheckRunOutput::headline()` does not change.
- Specs first: the `summary` description in `request-summary.schema.json` and `request.schema.json`, then the code.

## Depends on

F57 (journeys), F59 (request detail), F60 (request list).

## In scope

- `assets/routes/repositories/ReviewList.tsx` (or its container) and `assets/settings/TokenTable.tsx`, with their CSS modules.
- `assets/review/AgentPromptSnippet.tsx`.
- `assets/requests/RequestSidebar.tsx`, `assets/requests/LocationInfo.tsx` (or where the duplicate renders), and `assets/routes/requests/RequestDetail.tsx`.
- `src/Serializer/Normalizer/RequestSummaryNormalizer.php` and both schema descriptions.

## Out of scope

- The check-run headline.
- Other tables, and other widths than the two named.

## Acceptance tests

- **UI** at 390 px and 768 px: the repository page with reviews, and Settings with tokens, have `document.documentElement.scrollWidth <= innerWidth`.
- **UI:** "Connect your agent" in the Agent prompts overlay is a link without the muted class.
- **UI:** the request sidebar shows the anchor path once. A request with `commentCount: 0` shows no comment count in the state line.
- **Functional:** a request without a title whose body starts with `` Fix `Foo::bar()` in **all** places `` has the summary `Fix Foo::bar() in all places` in Q1, Q3, and MCP `get_request`. A title stays unchanged. A line of 250 characters after the strip ends with U+2026 at 200 characters.

## Verification

- `make check` is green.
- `make journeys`: the repository page and Settings at 390 px and 768 px, the request detail sidebar.
