# F82 Help center quote style

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-10-28: "the quote box in markdown isn't styled when looking at the docs page. It's just got some left padding. It needs to stand out more."

## Goal

A Markdown quote on a help page stands out from the text around it, in light and dark mode.

## Facts

Verified on 2026-10-28:

- `templates/docs/layout.html.twig` has no rule for `blockquote`. The browser default shows: a 40 px margin on both sides, no border, no background.
- `docs/user/index.md` opens with Jan's quote "The absolute TL;DR written by a human, for humans".

## Decisions

- A quote has neutral gray colours of Primer, not blue (Jan: "less blue please. More gray or stone"): the `bgColor-muted` background, a `borderColor-default` border with a 4 px `borderColor-emphasis` bar on the left, rounded corners, and the default text colour. The token values come from `@primer/primitives` 11.10.0.

## Depends on

None.

## In scope

- `templates/_theme.html.twig` (the token), `templates/docs/layout.html.twig`.

## Out of scope

- Quotes in the SPA (`MarkdownBody`).

## Acceptance tests

A style has no test.

## Verification

`make lint`. Screenshots of `/docs` at 1280 px and 390 px in light and dark mode.
