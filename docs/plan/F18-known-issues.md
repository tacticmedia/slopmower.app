# F18 Known issues

Workflow, conventions, and status: [feature track](features.md). Hard rule: bugs and issues before features (`AGENTS.md`).

## Goal

Fix the open known issues that [F15](F15-proposal-follow-ups.md) does not cover: a follow-up of the F13 report, the open "Remains" of the developer guide ledger, two "For Jan" items that the code conventions decide, and earlier report follow-ups. Each item was checked against `main` at 32e6524.

## Facts

- F15 takes the F13 follow-ups about the proposal label, the `invalid_transition` message, and the file view label.
- The other ledger "Remains" entries are reasons, not defects: the MCP lookups, the view classes, the constructor parameters of `ServerClone` and `RequestCommands`, `ReviewCommands`, the list sorts, and the `ulid` cursor type. They stay.
- The batch thread read (ledger row "Front end") is an additive read, not a defect. It moves from "For Jan" to the Candidates.

## Decisions

Jan, 2026-10-07: decide what the code, the specs, or a sensible default decides; only items that truly need Jan go to "For Jan". The three decisions below are of that kind.

- **Q9 file order, D51:** the spec defines the order that the server already returns. The client sort goes.
- **`IssuedApiToken`:** every response class is in `Application\Query`, as `LinkCommitsResult` and `LinkPullRequestResult` are. It moves there.
- **`@pierre/diffs`:** 1.5.2 is the latest release (2026-10-05). The pin moves to 1.5.2. The identity rule in `CodeFile.tsx` stays, because it is correct use of the library either way.

## Items

1. **A proposed diff offers an expansion that cannot work.** A patch that starts after line 1 shows the "N unmodified lines" separator of `@pierre/diffs` with an expand control, but the patch holds no file content. `ProposalDiff` uses a `hunkSeparators` variant without the expand control. Read the library source to pick it.
   - Evidence: `assets/code/CommitDiff.tsx:60-77`, F13 report.
2. **Q9 file order (D51).** `docs/specs/rest-api-requests.md` Q9 and the `files` description of `commit-diff.schema.json`: the files that touch the anchor come first, then the order of `git diff-tree`. `RequestChanges.tsx` drops its sort. The fake UI backend returns the spec order. Record D51 in architecture section 11 and specs README section 8.10. Update the ledger row `CommitDiffController`.
   - Evidence: `src/Controller/Api/CommitDiffController.php:115`, `assets/routes/requests/RequestChanges.tsx:88`.
3. **`IssuedApiToken` placement.** Move `App\Application\IssuedApiToken` to `App\Application\Query`. Update its users and every doc that names it.
4. **`@pierre/diffs` 1.5.2.** Pin `1.5.2` in `package.json` and `package-lock.json`. Compare `finalizeRender()` of 1.5.1 and 1.5.2, and record in the report whether it still compares the files by identity. The orchestrator updates "For Jan".
5. **`symfony/polyfill-mbstring`.** `composer.json` requires `ext-mbstring`, so `replace` gets `symfony/polyfill-mbstring`, as it has ctype and iconv. Update the lock with Composer.
   - Evidence: F08 report.
6. **Test logs hold every record.** In `when@test` and `when@e2e` of `config/packages/monolog.yaml`, the `fingers_crossed` handler has no `buffer_size`, and the `records` test handler takes every channel at `debug`. Give `main` a `buffer_size`, and keep only the channels that tests read in `records` (`GitHubFetchSecretTest` is the reader; check its channel). Record the PHPUnit peak memory before and after.
   - Evidence: F12 report.
7. **A failed GitHub sign-in says "An authentication exception occurred."** HWIOAuthBundle throws plain `AuthenticationException`s for a state mismatch or a denied authorization. The login page shows "Signing in with GitHub failed. Try again." instead, through the `security` translation domain or the template. Other messages stay.
   - Evidence: `templates/security/login.html.twig:10`, F06 report.

## Out of scope

- The items of F15, and the Candidates.
- A change to the Q9 wire format other than the defined order.

## Acceptance tests

- **UI:** a proposed diff that starts after line 1 has no expand control. Update the UI specs that depend on the client sort of Q9.
- **F:** Q9 returns the file that touches the anchor first when git lists it later, if no current test checks it.
- **F:** the GitHub callback with a wrong `state` shows "Signing in with GitHub failed. Try again." on the login page.

## Verification

- `make check` is green.
- `composer show symfony/polyfill-mbstring` finds nothing. `npm ls @pierre/diffs` shows 1.5.2.
- The report records the PHPUnit peak memory before and after item 6.
