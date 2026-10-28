# F78 Sign-in page design

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-10-28: "make the login page looking not like pulled-out-of-your-butthole please."

## Goal

`/login` has the Primer look of the SPA and the help pages, in light and dark mode, from 390 px wide.

## Facts

Verified on 2026-10-28:

- `templates/security/login.html.twig` has no CSS. The browser default styles show.
- `templates/docs/layout.html.twig` has the Primer tokens of `@primer/primitives` inline, in light and dark. The Twig pages load no Vite entry.
- `docs/user/getting-started.md`: the first sign-in creates the account.
- The tests select `h1` "Sign in to Slopmower", `a[href="/connect/github"]`, and `footer a`.

## Decisions

- The tokens and the base rules of the docs layout go into `templates/_theme.html.twig`, which the docs layout and the sign-in page include. The token values come from `@primer/primitives` 11.10.0.
- The GitHub sign-in layout: a centred column with the heading, one line about the product, a box with the sign-in buttons, a box with a link to the help center, and the legal links.
- "Sign in with GitHub" is a Primer primary button with the GitHub mark. "Sign in as <login>" is a Primer default button.
- No logo. Slopmower has none.
- The cookie notice button gets the Primer default button style.

## Depends on

None.

## In scope

- `templates/_theme.html.twig` (new), `templates/docs/layout.html.twig`, `templates/security/login.html.twig`.

## Out of scope

- A logo and a favicon.
- The SPA.

## Acceptance tests

The existing tests cover the content: `LoginRoutesTest`, `CookieNoticeTest`, `SignInTest`. A style has no test.

## Verification

`make check`. Screenshots of `/login` in light and dark mode at 1280 px and 390 px, and of a help page in both modes.
