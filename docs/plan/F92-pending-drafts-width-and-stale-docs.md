# F92 Known issues: pending drafts width and two stale docs

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "When looking at the list of requests, the pending drafts have either padding or margin, so they don't use the full width of the page. Make those drafts to be as wide as the list of requests below them." The two docs issues came up in the same session.

## Goal

- On the request list, each pending draft is as wide as the request list box below it.
- The user docs and the developer guide do not describe removed behaviour.

## Facts

Verified on 2026-11-03:

- `assets/routes/requests/RequestList.tsx:59-66` renders the drafts in `ThreadList` with `threads={NO_THREADS}`. `ThreadList.module.css:1-6` gives `.list` the padding `var(--base-size-12) var(--base-size-16)`, so each draft is 16 px narrower on each side than `.box` (`RequestList.module.css:7-11`). No max-width applies.
- `FileRoute.tsx:146,247` also uses `ThreadList`. There the padding is wanted.
- `docs/user/connect-your-agent.md:84` says: "If you want a pull request for the work, ask the agent for one, or put the rule into the instructions of your repository". Since F76 the "Submit feedback" dialog and the plan approval set this (`comment.md`, section "Submit feedback").
- `docs/developer-guide.md` ledger, row near line 368, names `RequestStateMapper`. D98 (F89) removed that class.

## Decisions

- `RequestList` renders `DraftBox` elements directly in the pending-drafts section, without `ThreadList`. The section already is a flex column with a gap (`Requests.module.css`, `.section`).
- No change to `ThreadList`.

## Depends on

Nothing.

## In scope

- `RequestList.tsx`.
- `docs/user/connect-your-agent.md` (the pull request paragraph).
- `docs/developer-guide.md` (the ledger row).

## Out of scope

- The pending drafts of the code view.

## Acceptance tests

- UI, `tests/ui/RequestListLayout.spec.ts`: the left and right edges of each pending draft equal those of the request list box.
- `PendingDrafts.spec.ts` keeps passing.

## Verification

- `make check` is green.
- `rg -n RequestStateMapper docs src` finds nothing.
