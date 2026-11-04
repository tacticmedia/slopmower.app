# F115 Return to the page after sign-in

Workflow, conventions, and status: [feature track](features.md). Scheduled by Jan on 2026-11-04 from the Candidates list. The candidate came from [F113](F113-comment-drafts.md).

## Goal

- After the session ends, **Sign in** returns the user to the page that was open. The stored text of D109 shows at once, and the user does not have to find the page again.

## Depends on

- F113: the dialog **You are signed out** and the stored comment text.

## Facts

Verified on 2026-11-04:

- `ShellController` has `#[IsGranted('IS_AUTHENTICATED')]`. Without a session, the exception listener of the `main` firewall stores the URL of a safe request that is not XHR as `_security.main.target_path`. Then `LoginRedirectEntryPoint` redirects to `/login`. `ShellRouteTest` checks the redirect, but not the stored path.
- Local sign-in: `LocalSignInAuthenticator::onAuthenticationSuccess()` redirects to the target path and removes it. `LocalSignInTargetPathTest` checks this.
- GitHub sign-in: the HWIOAuthBundle authenticator uses the success handler of the firewall, `DefaultAuthenticationSuccessHandler`, which reads the same key. The GitHub link of `/login` sends no `_target_path`, so `RedirectToServiceController` keeps the stored path. Read in `vendor/`, not run against GitHub.
- On the dev stack, `GET /reviews/{id}/files/composer.json` without a session answered 302 to `/login`. Then `POST /login/local` in the same session answered 302 to that URL.
- **Sign in** of the dialog in `RootLayout` and the link of the **Signed out** screen in `RouteError` open `/login` directly. No shell request runs, so no target path is stored, and sign-in lands on `/`.
- The F113 scope file says that the shell has no access control. That is wrong. The gap is in the front end only.

## Decisions

- **Sign in** reloads the current page with `window.location.reload()`. The server redirects the shell request to `/login` and stores the URL as the target path. Both sign-in methods return to it. No server change and no `target` parameter.
- Not `location.assign(location.href)`: when the URL has a hash, that changes only the hash and loads nothing.
- The browser does not send the hash, so the return lands on the page without it. F113 opens a stored file composer without the hash.
- While unsaved text exists, the reload shows the `beforeunload` prompt, as the link to `/login` does now. The text is in `sessionStorage` either way.
- D109 changes: **Sign in** reloads the page, so the firewall stores it as the target path of the sign-in. No new decision number.

## In scope

- `assets/layout/RootLayout.tsx`: **Sign in** of **You are signed out** reloads the page.
- `assets/layout/RouteError.tsx`: **Sign in** of the **Signed out** screen reloads the page.
- `docs/user/comment.md`: **Sign in** returns to the same page with the text.
- `docs/architecture.md`: D109.
- `docs/plan/features.md`: the candidate points to this file.

## Out of scope

- The hash of the URL, for example `#L3-L5`. The server never gets it.
- Sign-in in another tab, or after the tab closed. `sessionStorage` belongs to one tab.
- The explicit sign-out. It still lands on `/login`, the logout target.
- A `target` parameter of `/login`.

## Acceptance tests

- UI: in **You are signed out**, **Sign in** loads the current page again, with the same URL.
- UI: on the **Signed out** screen, **Sign in** loads the current page again, with the same URL.
- Functional: a shell request without a session redirects to `/login` and stores its URL as the target path of the `main` firewall.
- Functional: a GitHub sign-in callback after a shell request without a session redirects to that URL.
- `LocalSignInTargetPathTest` stays green.

## Verification

- `make check`.
- Manual, in Chrome on a dev stack with local sign-in: type a line comment, delete the session file, and make the tab visible. Click **Sign in** in **You are signed out**, then sign in. The file opens with the comment box and its text, and no page is opened by hand.
