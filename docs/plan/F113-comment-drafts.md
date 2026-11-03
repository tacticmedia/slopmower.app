# F113 Comment drafts and session keepalive

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-04, after an expired session lost a comment: each comment field gets its own ID, its text survives a reload in `sessionStorage`, the code is portable to other projects, and an expired session shows a dialog, after Workboard's `keepalive_controller.ts`.

## Goal

- A reload of the page does not lose the text of a comment field. The text comes back in the same field.
- An expired session does not lose the text. A dialog tells the user that the session ended.
- While a page is open, the session does not expire.

## Facts

Verified on 2026-11-04:

- The `php` container runs with `session.gc_maxlifetime=1440`, `session.gc_probability=1`, and `session.gc_divisor=1000`. No ini file of `docker/frankenphp/conf.d/` changes them. A session is idle after 24 minutes.
- Typing sends no request. The Mercure re-authorize runs 60 seconds before its 3,600-second lifetime ends, so it does not keep the session alive.
- `actionError()` rethrows a 401 to the route error boundary, which unmounts the screen with its comment box.
- The SPA shell has no access control, so Symfony stores no target path, and sign-in lands on `/`.
- React owns the value of every comment field. It sets an empty value after a save without an `input` event, so a DOM-level controller, for example Stimulus, cannot see it. Stimulus is not installed.

## Decisions

- One self-contained hook, `assets/layout/useSessionDraft.ts`, without Slopmower imports, so it copies to another React project. Its key prefix is the only Slopmower value. Jan chose a React hook over a Stimulus controller.
- Each field instance has its own key:

  | Field | Key after `slopmower.draft:` |
  |---|---|
  | Reply of a thread | `request:{requestId}:reply` |
  | Line comment | `review:{reviewId}:file:{encodeURIComponent(path)}:lines:{start}-{end}` |
  | File comment | `review:{reviewId}:file:{encodeURIComponent(path)}:file` |
  | New general request | `review:{reviewId}:new-request` |
  | Edit of a pending comment | `draft:{draftId}:edit` |
  | Note of a request command | `request:{requestId}:{intent}` |
  | Note of Request changes on a proposal | `proposal:{proposalEventId}:reject_proposal` |
  | Summary of Submit feedback | `review:{reviewId}:feedback-summary` |
  | Note of Close review | `review:{reviewId}:close-note` |

- Only a change of the text writes the key. Text equal to the initial text removes it. No effect writes, so a late render cannot store a key again after its write succeeded.
- The route action removes the key after its write succeeds, because a field can unmount before the write ends. The intent carries `draftKey` next to `input`, and a form carries it in a hidden field. A 401 keeps the key.
- The reset of a comment box after a save shows the stored text again, or the initial text, and does not write. The file composer can render once more after its submit, before its write ends, and a removal there would lose the text of a 401.
- Cancel, Escape, closing a dialog, and **Discard** of the unsaved-comment guard remove the key: an explicit discard.
- A field that exists only after a click opens again when its key exists: the comment composer of a file, the new general request, and the edit of a pending comment. The composer opens again on the lines of its key, without the URL hash, once per file when the file view mounts. With several stored composers of one file, the first key in sorted order opens. A restored comment on a symbol anchors to the symbol only when the symbols have loaded, as a click does.
- Sign-out removes every key after it succeeds.
- `assets/layout/useSessionKeepalive.ts` sends A1, `GET /api/v1/me`, after a random delay of 4 to 8 minutes, and when the tab becomes visible. One request runs at a time. A 401 stops it and opens the dialog "You are signed out", with **Sign in** and **Dismiss**. A reload of the SPA shows only the "Signed out" error screen, so **Sign in** replaces Workboard's Reload. Other errors wait for the next request. No coordination between tabs.
- An open tab keeps the session alive with no end, as Workboard does.
- D109 records the drafts and the keepalive.

## In scope

- `assets/layout/useSessionDraft.ts`, `assets/layout/useSessionKeepalive.ts`, `assets/layout/RootLayout.tsx`.
- `assets/comments/CommentBox.tsx`, `ThreadBox.tsx`, `DraftBox.tsx`, `UnsavedCommentGuard.tsx`, `useFileComments.ts`, `commentAction.ts`, `commentIntent.ts`.
- `assets/requests/NewRequestBox.tsx`, `NoteDialog.tsx`, `ActionBar.tsx`, `RequestActions.tsx`, `SuggestionActions.tsx`, `ProposalEntry.tsx`, `requestIntent.ts`.
- `assets/review/SubmitFeedbackOverlay.tsx`, `CloseReviewDialog.tsx`, `ReviewActionsMenu.tsx`, and `assets/routes/reviews/ReviewLayout.tsx`.
- `assets/api/actionResult.ts`, `assets/routes/reviews/FileRoute.tsx`, `fileData.ts`, `reviewData.ts`, `assets/routes/requests/requestData.ts`, `assets/router.tsx`.
- UI tests `tests/ui/CommentDrafts.spec.ts` and `tests/ui/SessionKeepalive.spec.ts`.
- `docs/architecture.md` (D109), `docs/user/privacy.md`, and the user and developer docs that describe comment boxes.

## Out of scope

- A return to the same page after sign-in. It needs a target path on the server. To the Candidates.
- The title, type, and group of a new request. Only textareas keep their text.
- The title editor of `RequestTitle` and the answers of `QuestionForm`, which are not textareas.
- A Stimulus controller.

## Acceptance tests

UI, on the mocked API:

- A reply typed on a request comes back after a reload. After the reply is sent, a reload shows an empty box.
- The replies of two requests keep separate text.
- A line comment opens again on its lines with its text after a reload, also when the page opens without the line hash. A comment on other lines keeps its own text.
- A comment write answered with 401 shows "Signed out". When the session works again, the file opens with the comment box and its text.
- An edited pending comment opens in edit mode with the edited text after a reload. Cancel discards the edit.
- A note dialog shows its text again when it opens again after a reload. Cancel removes it.
- **Discard** of the unsaved-comment guard removes the stored text, so the file opens without the comment box.
- The summary of Submit feedback comes back after a reload and is gone after the submit succeeds.
- Sign-out removes the stored text.
- A1 answered with 401 at the next keepalive request opens "You are signed out", and A1 then stops. Dismiss closes the dialog.
- A tab that becomes visible sends A1 at once. With a 401, the dialog opens, and Sign in opens `/login`.

## Verification

- `make front`, `make ui`, and `make check` pass.
- Manual, on the dev stack: a typed line comment survives a reload. After the session file is deleted, the dialog opens when the tab becomes visible. After sign-in, the file opens with the comment box and its text.
