# F80 Bug report widget

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-10-28: "I want to enable the Sentry user feedback widget https://docs.sentry.io/product/user-feedback/". F77 left User Feedback out of scope.

## Goal

With `SENTRY_DSN_FRONTEND` set, every SPA page shows the Sentry "Report a Bug" button. A user sends a description, and optionally a name, an email address, and a screenshot, to the browser Sentry project.

## Facts

Verified on 2026-10-28 in the installed `@sentry/react` 11.9.0, the latest release:

- `@sentry/react` re-exports `@sentry/browser`, whose `feedbackIntegration` is `feedbackSyncIntegration`. It bundles the form and the screenshot tool, so nothing loads from the Sentry CDN.
- Defaults: `autoInject: true` puts a fixed button at the bottom right, in an open shadow root. `colorScheme: 'system'` follows `prefers-color-scheme`, as Primer `colorMode="auto"` of `assets/app.tsx` does. Name and email fields show, both optional. `enableScreenshot: true`. The labels are "Report a Bug" and "Send Bug Report".
- `useSentryUser` fills name and email from the Sentry user. The SPA sets no user (D86), so the fields start empty.
- A screenshot uses `getDisplayMedia()` with `preferCurrentTab`, so the browser asks the user to share the tab. The user can highlight or hide parts of it.
- Opening the form sends the buffered session replay (`openFeedbackWidget`, `sendBufferedReplayOrFlush()`), and the feedback event gets its `replay_id`. A replay keeps the masking of D86.
- The submit sends an envelope item of type `feedback`. The widget uses no cookie or browser storage.

## Decisions

- **D87:** `feedbackIntegration()` with the SDK defaults, next to the integrations of D86. No own labels: "Report a Bug" does not clash with "Submit feedback" of F75.
- The screenshot stays on. The user adds it on purpose, and the privacy policy says that it can show code.
- No Sentry user for the form. The user types a name or an email address only to get an answer.

## Depends on

F77 (D86).

## In scope

- `assets/sentry.ts`.
- **UI** `tests/ui/SentryReports.spec.ts`.
- **Docs:** D87, `docs/deployment/README.md` "Error reporting", `docs/user/privacy.md`, `docs/user/support.md`.

## Out of scope

- The button on the Twig pages: sign-in, docs, and legal pages have no browser SDK.
- A "Report a Bug" item in the Help menu in place of the fixed button.
- Feedback when `SENTRY_DSN_FRONTEND` is empty.

## Acceptance tests

- **UI** `SentryReports`: "Report a Bug" opens the form, and "Send Bug Report" sends one `feedback` item with the description, the release, the environment, and no IP inference.

## Verification

`make lint`, `make front`, `make ui`.
