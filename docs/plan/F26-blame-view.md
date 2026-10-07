# F26 Blame view

Workflow, conventions, and status: [feature track](features.md). Jan's request of 2026-10-08, split from [F25](F25-github-style-code-browser.md): a blame view as on GitHub, after the code browser redesign.

## Goal

The file toolbar has a "Code | Blame" switch, as on GitHub. "Blame" shows, for each group of lines, the commit that last changed it at the review commit: author, date, and message, with the code beside it.

## Decisions

- **Specs first.** A new V endpoint and its JSON Schema return the blame of a file at the review commit, from `git blame --porcelain`, with the blob SHA as ETag. The endpoint ID, the schema, the error codes, and the size limit are decided in `docs/specs/` before any code, and the decision goes into [architecture section 11](../architecture.md#11-decisions).
- **GitHub reference first.** F26 starts with the reverse-engineering of GitHub's blame view with the `chrome-devtools` MCP server, as F25 did for the file view: layout, the commit column, the age colours, "Blame prior to change", the sticky header, the scroll behaviour.

The other decisions stay open until F26 starts.

## Depends on

[F25](F25-github-style-code-browser.md): the toolbar, the window-scroll code view, and the sticky header.

## In scope

To be set when F26 starts. At least: the spec and schema, the git reader and controller, the endpoint tests, the "Code | Blame" switch, the blame UI, and the docs.

## Out of scope

To be set when F26 starts.

## Acceptance tests

To be set when F26 starts.

## Verification

To be set when F26 starts.
