# F81 Sign-in page layout

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-10-28: "the logged out screen looks like ass. Make it look better."

## Goal

`/login` tells a new visitor what Slopmower is and shows the product, next to the sign-in buttons. Light and dark mode, from 390 px wide.

## Facts

Verified on 2026-10-28:

- After [F78](F78-sign-in-page-design.md), `/login` is a narrow centred column at the top of an empty white page: heading, Jan's tagline, a box with the buttons, a box with a help link, and the legal links at the bottom of the window.
- The tests select `h1` "Sign in to Slopmower", `a[href="/connect/github"]`, and the three `footer a` links of `_legal_links.html.twig`.
- Jan wrote the tagline and the "New to Slopmower? See how it works" line in `4d1dd45`.

## Decisions

- Two panes from 768 px: a dark brand pane on the left, the sign-in pane on the right. Below 768 px the brand pane is a compact band above the sign-in pane, without the preview.
- The brand pane has the wordmark "Slopmower" as text, Jan's tagline as the large line, one sentence from the help center about the workflow, and a static preview: a code excerpt with a comment thread between a user and an agent. The preview is `aria-hidden`, because it only illustrates the sentence.
- The brand pane is dark in both colour modes, in warm stone grays with a faint green glow, not the blue-black of Primer (Jan: "less blue please. More gray or stone"). The code excerpt uses the dark syntax colours of `@primer/primitives` 11.10.0. The sign-in pane keeps the Primer tokens of `_theme.html.twig`.
- Below 768 px the page is a flex column, not a grid, so the brand band keeps its content height and the sign-in pane takes the rest.
- Jan's copy stays as it is. The `h1`, the buttons, the error alert, and the footer keep their markup for the tests.
- No logo. F78 keeps it out of scope.

## Depends on

[F78](F78-sign-in-page-design.md).

## In scope

- `templates/security/login.html.twig`.

## Out of scope

- A logo and a favicon.
- The help pages and the SPA.

## Acceptance tests

The existing tests cover the content: `LoginRoutesTest`, `LocalSignInStillDevOnlyTest`, `CookieNoticeTest`, `SignInTest`, `DeleteAccountFlowTest`. A style has no test.

## Verification

`make lint`, `make test`, `make e2e`. Screenshots of `/login` at 1280 px and 390 px in light and dark mode, with the GitHub button and an error added to the DOM.
