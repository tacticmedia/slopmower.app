# F34 Double-submit CSRF on Twig forms

Workflow, conventions, and status: [feature track](features.md). Jan's request on 2026-10-08, after a review of F30. It replaces the F30 logout listener.

## Goal

Every CSRF-checked request of the browser sends both proofs of `SameOriginCsrfTokenManager`: the origin headers and a double-submit token. A session therefore never moves down from double-submit to origin-only, so no form of the app fails the downgrade check, after a sign-out or in any other state of the session.

## Facts

- `SameOriginCsrfTokenManager` records in the session which proofs a request used, and refuses a later request of the session that lacks one of them. Its docblock calls this a deliberate check against a downgrade "once a more secure method has been confirmed as functional".
- The React client sends double-submit on every write and on sign-out (`doubleSubmitCsrfToken()` in `assets/api/client.ts`). The Twig form "Sign in as <login>" has no script, so it sends only the origin headers.
- F30 reset the record on `LogoutEvent`. A session that loses its user without a logout keeps the record: when `TenantUserProvider::refreshUser()` finds no user, Symfony removes the token and keeps the session. The sign-in form of that session fails.
- At logout, `SameOriginCsrfListener` writes the record of the old session into the session that logout starts. With double-submit on every form, that record is harmless. It stays an upstream report.
- The Twig pages load no script bundle, and no content security policy applies to them. Symfony's documented protocol: on submit, a random token goes into the hidden `_csrf_token` field and into the cookie `<cookie name>_<token>` with the value `<cookie name>`, `samesite=strict`, and the `__Host-` prefix on HTTPS.

## Decisions

- **An inline script in `templates/base.html.twig`**, outside the overridable blocks, so every Twig page has it. A `submit` listener on the document gives each form with a stateless `_csrf_token` placeholder a fresh token and its cookie. It leaves a field that holds a session token alone, as Symfony's snippet does. No Vite entry: the sign-in page then works without the Vite dev server.
- **`ResetCsrfStrategyListener` goes**, with its F30 test case: with double-submit on every form, the record that it reset does no harm. One mechanism remains.
- `AccountController::delete()` keeps its own handling: it removes the attribute so that the 202 response starts no session.
- D61 in [architecture section 11](../architecture.md#11-decisions).

## Depends on

F30.

## In scope

- `templates/base.html.twig`.
- Delete `src/Security/ResetCsrfStrategyListener.php` and the F30 case of `LogoutTest`.
- An E2E case.
- Docs: architecture (section 4 "Sign-in and sessions", D61), developer guide (`App\Security` count), `features.md` (F30 row, upstream report, F34 row), the F30 scope and report.

## Out of scope

- The React client.
- The upstream report.

## Acceptance tests

- **E2E:** sign in, sign out with the header button (double-submit, so the new session records it), then submit a form with the stateless placeholder on the sign-in page. It passes the check and redirects to `/login`. Without the script it gets 403. The `e2e` site has no CSRF-checked form of its own, because local sign-in exists only in `dev`, so the case adds the form to the page.
- The other E2E, functional, and UI tests stay green.

## Verification

- `make check` is green.
- Manual check in the dev app: sign in, sign out, sign in again.
