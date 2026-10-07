# F30 Sign in after sign-out

Workflow, conventions, and status: [feature track](features.md). A bug found during F28 on 2026-10-08. It exists with `PdoSessionHandler` too, so F28 did not cause it. Bugs before features: it goes ahead of every feature that is not started.

## Goal

After "Sign out", the user signs in again in the same browser without "Invalid CSRF token".

## Facts

- Reproduction in the dev app with local sign-in: sign in, press "Sign out" in the header, press "Sign in as jan". The login page shows "Invalid CSRF token.", and the log has "CSRF validation failed: double-submit info was used in a previous request but is now missing." Reproduced with file sessions (F28) and with the session configuration of `63b08cd` (`PdoSessionHandler`).
- `SameOriginCsrfTokenManager::isTokenValid()` (`vendor/symfony/security-csrf/SameOriginCsrfTokenManager.php:150-188`) reads the strategies of earlier requests from the session attribute `csrf-token`, adds the strategy of the current request, and puts the result into the request attribute `csrf-token`. On the response, `SameOriginCsrfListener` writes that request attribute into the session.
- The API calls of a signed-in page send the double-submit token (`check_header: true` in `config/packages/csrf.yaml`), so the session records double-submit. The logout request carries that record into its request attribute. Logout invalidates the session, and the response then writes the record into the new session.
- The login form posts only the origin information. With double-submit recorded, `isTokenValid()` refuses it.

## Decisions

- Prefer a `LogoutEvent` listener that removes the request attribute `csrf-token` after the session is invalidated, so the new session starts without the strategies of the old one. Check the order of the logout listeners and of `SameOriginCsrfListener` in the installed Symfony before the change.
- A double-submit token on the login form also avoids the refusal, but the next sign-out would record the same history again. Use it only if the listener does not work, and record why in the report.

## Depends on

None.

## In scope

- The fix above.
- An E2E case.

## Out of scope

- Other CSRF behaviour.
- The GitHub sign-in, unless the E2E case shows that it has the same refusal.

## Acceptance tests

- **E2E:** in one browser context, sign in, sign out from the header, and sign in again; `/api/v1/me` answers with the user. The case fails before the fix.
- The existing E2E and functional sign-in tests stay green.

## Verification

- `make check` is green.
- Manual check in the dev app: sign in, sign out, sign in again.
