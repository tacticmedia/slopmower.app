# F83 Logo and favicon

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-10-28: "make a logo and favicon".

## Goal

Slopmower has a logo mark. It shows next to the name on the sign-in page, in the help-center header, and in the SPA header, and it is the favicon of every page.

## Facts

Verified on 2026-10-28:

- `public/` holds only `index.php` in git. Caddy serves `public/` as static files in dev and in production (`root /app/public`), and the production image copies it.
- `templates/base.html.twig` has no icon link. The sign-in page, the help pages, and the SPA shell extend it.
- The SPA brand is the text link "Slopmower" in `assets/layout/RootLayout.tsx`. The mocked UI suite serves the SPA from `tests/ui/app` without `public/`.
- `ImageMagick` and `rsvg-convert` are on the host. Sources disagree on SVG favicons in Safari, so an ICO and an Apple touch icon go with the SVG.

## Decisions

- The mark is a rotary mower blade seen from above: two tapered arms on a hub that together form an "S". Green `#3fb950` on a stone tile `#1c1917` with a `#44403c` rim, the colours of the F81 brand pane.
- One source: `public/favicon.svg`, 32 x 32 view box. `public/favicon.ico` (16 and 32 px) and `public/apple-touch-icon.png` (180 px, full bleed, no rim) are rendered from it. The headers show `favicon.svg` as an `<img>` with an empty `alt`, so the accessible name of each brand link stays the text.
- `base.html.twig` links the three files with `asset()`.
- The mocked UI suite answers `/favicon.svg` from `public/`.

## Depends on

[F81](F81-sign-in-page-layout.md).

## In scope

- `public/favicon.svg`, `public/favicon.ico`, `public/apple-touch-icon.png` (new).
- `templates/base.html.twig`, `templates/security/login.html.twig`, `templates/docs/layout.html.twig`.
- `assets/layout/RootLayout.tsx`, `assets/layout/RootLayout.module.css`, `tests/ui/support/mockApi.ts`.
- `tests/Functional/Security/LoginRoutesTest.php`.

## Out of scope

- A web app manifest and Android icons.
- The Marketplace listing images (For Jan, item "GitHub App").

## Acceptance tests

- `LoginRoutesTest`: `/login` links `/favicon.ico`, `/favicon.svg`, and `/apple-touch-icon.png`, and each file exists in `public/`.

## Verification

`make lint`, `make test`, `make e2e`, `make front`, `make ui`. Screenshots of the sign-in page, a help page, and the SPA header in light and dark mode, and the tab icon.
