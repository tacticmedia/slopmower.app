# F43 Legal and support pages

Workflow, conventions, and status: [feature track](features.md). Every GitHub Marketplace listing needs a privacy policy link and a support link or email (F42 facts). The listing form also takes a terms of service URL.

## Goal

A privacy policy, terms of service, and a support page that everyone can read without sign-in, at a stable URL for the listing.

## Decisions

- **Pages.** `docs/user/privacy.md`, `docs/user/terms.md`, and `docs/user/support.md`. `src/Docs/DocsCatalog.php` renders every page of `docs/user/` at `/docs/<slug>` without sign-in (D28), so no code is necessary for the pages.
- **Links.** The sign-in page (`templates/security/login.html.twig`) and the pages table of `docs/user/index.md` link all three.
- **Privacy content from the architecture:**
  - stored data: clones of private repositories, the index, reviews and requests, and the GitHub profile (login, name, avatar);
  - no code in logs ([architecture section 12](../architecture.md#12-risks));
  - deletion: clones and index at once when access ends (D22), account deletion (M31), and 30 days after a cancellation of the plan (F42);
  - the storage country, which Jan supplies.
- The GitHub Marketplace Developer Agreement requires these disclosures.
- **Legal text.** Jan supplies or approves the privacy and terms text, the storage country, and the support email. It is the "For Jan" item "Legal and support pages".

## Depends on

F42: the deletion rule after a cancellation, which the privacy page states.

## In scope

The three pages, the links on the sign-in page and the docs index, and a test.

## Out of scope

- A cookie banner. Jan decides with the legal text whether one is necessary.
- Translations.

## Acceptance tests

- **F** `DocsRenderingTest`: `/docs/privacy`, `/docs/terms`, and `/docs/support` render without sign-in.
- **F**: the sign-in page links the three pages.

## Verification

- `make check` is green.
- Jan approved the text. The report records the date.
