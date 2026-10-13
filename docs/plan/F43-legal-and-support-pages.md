# F43 Legal and support pages

Workflow, conventions, and status: [feature track](features.md). Every GitHub Marketplace listing needs a privacy policy link and a support link or email (F42 facts). The listing form also takes a terms of service URL.

## Goal

- A privacy policy, terms of service, and a support page that everyone can read without sign-in, at a stable URL for the listing.
- A cookie notice on every page.
- Text for a global audience.

## Decisions

- **Pages.** `docs/user/privacy.md`, `docs/user/terms.md`, and `docs/user/support.md`. `src/Docs/DocsCatalog.php` renders every page of `docs/user/` at `/docs/<slug>` without sign-in (D28), so no code is necessary for the pages.
- **Links.** The sign-in page (`templates/security/login.html.twig`) and the pages table of `docs/user/index.md` link all three.
- **Privacy content from the architecture:**
  - stored data: clones of private repositories, the index, reviews and requests, and the GitHub profile (login, name, avatar);
  - no code in logs ([architecture section 12](../architecture.md#12-risks));
  - deletion: clones and index at once when access ends (D22), account deletion (M31), and 30 days after a cancellation of the plan (F42);
  - the storage country, which Jan supplies.
- The GitHub Marketplace Developer Agreement requires these disclosures.
- **Global audience.** Jan, 2026-10-13. The privacy policy covers:
  - the GDPR and the UK GDPR: the controller and its contact, the legal basis of each use, the rights of the user, the retention, and international transfers;
  - the CCPA as amended by the CPRA: the categories of personal information, and that Slopmower does not sell or share it;
  - the Australian Privacy Act: the Australian Privacy Principles and a complaint to the OAIC;
  - the processors: GitHub, and the hosting provider that Jan supplies;
  - no use by children, and how changes to the policy are announced.
  - The terms name the governing law, which Jan supplies, and say that the GitHub Marketplace bills the paid plans (D65).
- **Cookie notice.** Jan, 2026-10-13: a cookie banner is necessary.
  - Slopmower sets only cookies that the service needs: the session cookie, the CSRF double-submit cookie (D61), and the Mercure subscriber cookie. The browser also keeps `localStorage` keys with the prefix `slopmower.` (D14).
  - So the banner informs and links the privacy policy, and it asks for no consent: no cookie is optional, and a refusal could change nothing.
  - "Got it" closes the banner, and `localStorage` remembers it.
  - `templates/base.html.twig` shows it, so the sign-in page, the docs, and the app shell show it.
  - The privacy policy lists each cookie and storage key with its purpose and lifetime.
  - An optional cookie, for example for analytics, needs a consent choice before the cookie is set. That is out of scope.
- **Legal text.** The implementation writes a draft. Jan supplies the facts and approves the text. It is the "For Jan" item "Legal and support pages".

## Depends on

F42: the deletion rule after a cancellation, which the privacy page states.

## In scope

The three pages, the links on the sign-in page and the docs index, the cookie notice in `templates/base.html.twig`, and the tests.

## Out of scope

- A consent choice for optional cookies. Slopmower sets none.
- Translations.

## Acceptance tests

- **F** `DocsRenderingTest`: `/docs/privacy`, `/docs/terms`, and `/docs/support` render without sign-in.
- **F**: the sign-in page links the three pages.
- **E2E** `CookieNoticeTest`: the notice shows on the first visit and links the privacy policy, "Got it" closes it, and it stays closed after a reload.

## Verification

- `make check` is green.
- Jan approved the text. The report records the date.
