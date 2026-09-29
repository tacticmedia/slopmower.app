# Tool findings

Part of [the architecture document](../architecture.md). Step 1: the existing tools, read from source.

The phase 1 spikes, clones, transcripts, and most screenshots were in a temporary scratch directory. They are not in the repository. The screenshots that the main document uses are in this directory. Paths such as `spikes/...` or `tools/...` refer to that scratch directory.

## Batch Review (hevangel/batch-review-mcp)

Read at `831d427` (2026-09-05), version 1.3.0. Python 3.13 (FastAPI + FastMCP) back end, React 18 + Monaco front end. MIT. 2 stars, 1 contributor policy "AI-authored only" (`CONTRIBUTING.md:3`). No automated tests.

| Aspect | Fact |
|---|---|
| Storage | Live state in process memory only (`backend/state.py:32`). Written on explicit save as a JSON array plus a Markdown report to `{repo}/logs/batch_review/review_comments.{json,md}` (`main.py:206`, `state.py:516-541`). Non-atomic write, no lock, no schema version, no commit SHA. Unsaved work is lost when the process stops. |
| Data model | Pydantic `Comment` (`backend/models.py:29-63`): `id` uuid4, `file_path`, `line_start`, `line_end` (1-based, `0/0` = whole file), `reference` (`@path:L7-9`), `text`, `highlighted_text` (snapshot), region/page/document/HTML anchor fields for images, PDF, DOCX/PPTX, HTML, `created_at`, `outdated` bool. No status, author, thread, replies, or SHA. |
| Stale detection | `comment_staleness.py:68-132`: compare the current lines at the stored range with `highlighted_text` (EOL-normalised equality, substring, or per-line whitespace collapse). Reads the working tree. Runs at startup, on load, and on request only. |
| Re-anchoring | None. Line numbers never move. Two inserted lines above a comment make it `outdated`; "refresh anchor" then stores the wrong text at the old lines (agent test). Empty snapshot is never stale. Markdown-preview comments store rendered text and give false positives. |
| Lifecycle | None. "Done" = delete. No audit trail. |
| Agent interface | 20 MCP tools over stdio and Streamable HTTP (FastMCP, protocol `2025-11-25` / `2026-07-28`), REST, and a CLI (`batch-review <verb>`, JSON on stdout, `{"error","hint"}` with exit 1). The skill is a reviewer workflow; no file defines a fixer workflow (list, claim, fix, report). |
| UI | Three panels: lazy file tree, Monaco read-only viewer (loaded from a CDN at runtime), comment cards in a right panel. No inline threads, no gutter "+", no replies, no resolve. |
| Concurrency | No locks anywhere. Last writer wins. In `--mcp` mode two event loops change the same dict. |
| Security | No auth, CORS `*` (`app.py:70-77`), `save` accepts any output directory, `file_path` not checked on create (agent test accepted `../../etc/passwd`). |

Claimed gaps: "line-range anchors only" is partly wrong (whole-file and document-region anchors exist; no symbol anchors, no columns). "No lifecycle" and "not GitHub-like" are confirmed.

**Reuse decision.** Ideas only, no code (Python back end; front end has no inline comment rendering).
- Keep: snapshot of the commented text; agent CLI shape (one JSON document on stdout, progress on stderr, non-zero exit); JSON plus Markdown export; path confinement on every write; push of UI updates to the browser.
- Do not copy: memory-only state; non-atomic writes; no SHA; no re-anchoring; no lifecycle; open CORS and unchecked paths; Monaco from a CDN.

## CodeReview.json (`poalrom.codereview-json`)

Source: https://github.com/poalrom/vscode-local-code-review-extension (found in the VSIX `extension/package.json:10-13`; the Marketplace page does not link it). Read at `df806b5` (2026-07-02). VSIX 0.0.2 is byte-identical to the repository build.

| Aspect | Fact |
|---|---|
| Storage | `.review/<name>.log.jsonl` (append-only events, one JSON per line, `fs.appendFileSync`), `.review/<name>.view.json` (projection, temp file + rename), `.review/state.json` `{active: string\|null}` (`src/core/storage.ts:9-17,25-28,50-58,75-78`) |
| Events | `add_thread {op, id, file, range{startLine,endLine}, snapshot, author, body, ts}`, `reply {op, thread, author, body, ts}`, `resolve {op, thread, ts}`, `reopen {op, thread, ts}`, `edit_comment {op, comment, body, ts}` (`src/core/types.ts:34-80`). No actor on resolve/reopen/edit. No runtime validation (`storage.ts:42`). |
| IDs | Thread `t_` + random base36 (`ids.ts:1-4`). Comment id = position in thread (`events.ts:18,31`), not stored; breaks after a merge that changes order (proved by agent probe P5). |
| Order | File line order. `ts` is not used. A reply before its `add_thread` is dropped. |
| Projection | Pure reducer `fold()` (`events.ts:3-71`), rebuilt on every read, no cache. |
| Anchor | Full lines of the selection, CRLF to LF (`commands.ts:126-138`). |
| Re-anchor | `anchor.ts:8-49`: exact block equality at stored range, else full scan for exact block, nearest to old start wins, else `outdated`. No whitespace normalisation, no fuzzy match, no git. Indentation change or one changed line gives `outdated`. Blank-line or `}` snapshots relocate falsely. New position is never saved. `outdated` is runtime-only; the agent cannot see it. |
| Lifecycle | `open` / `resolved` only (`types.ts:7`). No authorisation. Agent may reply/resolve/reopen by skill convention. |
| Agent interface | No CLI, no MCP (README `:128-130`). `skills/review-comments/SKILL.md` teaches `jq` reads of the view and `>>` appends to the log. Valid Agent Skills frontmatter. Faults: `grep -nF` multi-line check gives false positives; the `jq` fallback fold disagrees with the TypeScript fold. |
| UI | VS Code Comments API (`createCommentController`, `createCommentThread`, `CommentReply`), TreeView sidebar. VS Code only. |
| Concurrency | O_APPEND by both writers; no lock. `state.json` read-modify-write, last write wins. Default git merge of the log gives conflict markers; `merge=union` keeps both lines in merge-side order. |
| License | MIT, (c) 2026 Alex Popkov (`LICENSE:1-3`). |
| Maturity | 1 contributor, 41 commits from 2026-06-15 to 2026-07-02, 0 stars, 17 Marketplace installs, no activity for 3 months. |

**Reuse decision.** Reuse ideas only, no code, no dependency.
- Keep: event log as the source of truth with one pure reducer; the small event vocabulary; "outdated" as a derived flag; snapshot match nearest to the old position as a fallback step; Agent Skills as the agent contract.
- Do not copy: positional comment ids; events without an actor; exact-only matching; a range that is never re-computed for the agent; hand-written `jq` writes by the agent (our agent calls a validating CLI or MCP tool); hard delete of a review; no SHA pinning.

## difit (yoshiko-pg/difit)

Read at `82a765c` (v5.0.12, 2026-08-28). MIT, (c) 2025 @yoshiko-pg/difit. 3198 stars, active. Node CLI (Commander) + Express 5 server + React 19 / Vite 8 / Tailwind 4 SPA.

| Aspect | Fact |
|---|---|
| Diff viewer | Own renderer: an HTML table, one row per line (`src/client/components/DiffChunk.tsx:414-576`, `DiffLineRow.tsx:67-97`). Not a library. |
| Highlighting | prism-react-renderer 2.4.1, each line tokenised alone (`DiffCodeLine.tsx:76-82`). Multi-line constructs mis-colour (keywords inside PHP docblocks are coloured in the spike). |
| Line numbers | Unified mode: two columns (old, new). |
| Selection | Hover shows a speech-bubble button on the line number (not "+"). Drag from the button, or click then shift-click. Range cannot cross hunks (state per chunk, `DiffChunk.tsx:83-95`). Keyboard `c`. |
| Widget position | New table row under the last line of the range (`DiffChunk.tsx:261-271,541-569`). |
| Data model | `DiffCommentThread {id, filePath, createdAt, updatedAt, position {side: 'old'\|'new', line: number\|{start,end}}, codeSnapshot?, messages[]}`, `DiffCommentMessage {id, body, author?, createdAt, updatedAt}` (`src/types/diff.ts:28-124`). No status, no SHA, no file or symbol anchor. |
| Storage | Browser localStorage per origin + server memory. Nothing on disk. Restart on another port loses all comments (spike). |
| Anchoring | `(filePath, side, line)` inside one base..target selection. "Outdated" = snapshot differs from text at the same lines (`utils/outdatedComments.ts:34-58`). No relocation. |
| Agent interface | "Copy prompt" text: `path:L40-L46` + body, threads joined by `=====` (`src/utils/commentFormatting.ts`). Printed to stdout on exit. CLI `difit comment get/add/resolve --port N`. No MCP. "Resolve" deletes the thread. |
| GitHub-likeness | Has inline threads, replies, Markdown, suggestion blocks, file tree with counts, per-file "Viewed" checkbox, split/unified. Lacks: resolved state, pending review batch, file and symbol comments, avatars/timestamps. |
| Reusability | Not a library: no `exports`, components are app-internal. `App.tsx` is a 1608-line state monolith. Candidate copy set measured at 31 files / 4,258 LOC with hardcoded `/api/*` fetches and hidden context providers. |

**Spike (full unchanged file).** No whole-file mode exists.
- HEAD with no change: FAIL ("No differences found"; `HEAD HEAD` is rejected, `src/cli/utils.ts:206-212`).
- Diff against the git empty tree `4b825dc642cb6eb9a060e54bf8d69288fbee4904`: PASS as data, but every line shows as added (green, `+`). See `difit-b-empty-base-unified-range-thread.png`.
- `--context 99999`: neutral lines with two number columns, but only for a file that has at least one change. See `difit-c2-context-99999-range-thread.png`.
- Performance: 4,267 lines rendered in 444 ms, 186,662 DOM nodes (no virtualisation; files render lazily).

**Reuse decision.** No dependency and no code copy.
- The components are not packaged for reuse. Copying 4,258 LOC of app-internal code and then changing it for a full-file view is the same as writing our own viewer, which Principle B forbids.
- Keep as ideas: the empty-tree trick; the snapshot "Outdated" check; the prompt location format `path:Lx-Ly`; the viewed-file index; the agent loop (`--background` handshake, `comment get --format json`).
- Do not copy: localStorage and memory storage; "resolve = delete"; per-line Prism tokenisation; the `App.tsx` monolith.

## GitHub orphan-branch PR method

Research only (no push, no PR). Git part tested locally.

**Recipe (tested locally, plumbing, no working-tree change).**
```sh
PIN=$(git rev-parse HEAD)
EMPTY=$(git hash-object -t tree /dev/null)                 # 4b825dc642cb6eb9a060e54bf8d69288fbee4904
BASE=$(git commit-tree "$EMPTY" -m "review base: empty root")
git branch review/base "$BASE"
HEADC=$(git commit-tree "$PIN^{tree}" -p "$BASE" -m "review snapshot of $PIN [skip ci]")
git branch review/head "$HEADC"
```
A true orphan base with no shared commit gives "entirely different commit histories" in the UI (community reports, not in the official docs; https://github.com/orgs/community/discussions/121983). The shared empty root gives a merge base, so `git diff base...head` lists every file as added (`@@ -0,0 +1,N @@`). That GitHub accepts this PR is UNVERIFIED (no push allowed).

**Limits** (https://docs.github.com/en/repositories/creating-and-managing-repositories/repository-limits):
- Whole PR diff: 20,000 loadable lines or 1 MB. One file: 20,000 lines or 500 KB. Files above 400 lines or 20 KB need a "Load diff" click.
- Files: 300 in the classic view. The new Files changed page (default since 2026-01-22) shows 1,000, or 3,000 in an experimental mode.
- This Symfony skeleton alone (59 files, 13,201 lines, 523 KB) uses 66% of the line budget. A real application needs many sharded PRs.
- Content writes: about 80 per minute and 500 per hour (https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api).

**API** (https://docs.github.com/en/graphql/reference/objects#pullrequestreviewthread):
- `PullRequestReviewThread`: `id`, `isResolved`, `isOutdated`, `isCollapsed`, `path`, `line`/`startLine` (current, null when outdated), `originalLine`/`originalStartLine`, `diffSide`, `subjectType` (FILE | LINE), `comments`, `resolvedBy`, `viewerCanResolve`. `reviewThreads` has no filter on `isResolved`.
- Mutations: `resolveReviewThread` (optional `resolutionReason` ADDRESSED | INVALID | WONT_FIX, "Copilot code review" threads only, added 2026-08-25, no read-back field), `unresolveReviewThread`, `addPullRequestReviewThread`, `addPullRequestReviewThreadReply`, `addPullRequestReview` (no `event` = PENDING), `submitPullRequestReview` (APPROVE | COMMENT | DISMISS | REQUEST_CHANGES), `markFileAsViewed` (state VIEWED | UNVIEWED | DISMISSED).
- REST can create line, range (`start_line`), and file (`subject_type=file`) comments, but has no thread object and cannot resolve (https://docs.github.com/en/rest/pulls/comments).
- Official GitHub MCP server v1.12.2: `pull_request_read` (method `get_review_comments` gives threads with `isResolved`/`isOutdated`), `add_reply_to_pull_request_comment`, `pull_request_review_write` (methods `create`, `submit_pending`, `resolve_thread`, `unresolve_thread`), `add_comment_to_pending_review` (https://github.com/github/github-mcp-server/blob/main/README.md).

**After a push to the head branch.** A comment follows an unchanged line (`line` changes, `originalLine` stays). A changed line makes the thread "Outdated" with `line = null`. Outdated and resolved are independent. File-level comments reportedly go outdated on any push (https://github.com/orgs/community/discussions/86527). "Viewed" resets to DISMISSED when the file changes.

**Assessment.**
- Pros: zero build; the real GitHub UX; line, range, and file anchors; pending review; API and MCP access for the agent; fix commits are easy to audit one by one.
- Cons: diff limits force sharding; all code goes to GitHub; PR noise, CI triggers (needs `[skip ci]` on every commit), merge risk; only 2 thread states; no symbol anchor; no re-anchoring beyond "outdated"; the PR author cannot Approve or Request changes; an agent with the human's token posts as the human.
- Decision: not the product, but the UX reference and the state vocabulary. Lessons: `resolved` = verified by the human; keep an original anchor and a nullable current anchor (`originalLine` / `line`); "outdated" is a hint, not a state change; copy Viewed with reset, pending review batch, and a Conversations list filtered by state; reuse GitHub's reason words (ADDRESSED, WONT_FIX, INVALID) in a later export.

**GitHub PR review UX reference** (https://docs.github.com/en/pull-requests/how-tos/review-pull-requests/commenting-on-a-pull-request, https://docs.github.com/en/pull-requests/how-tos/review-pull-requests/reviewing-proposed-changes-in-a-pull-request, https://docs.github.com/en/get-started/accessibility/keyboard-shortcuts):
file tree with filter (`T`) and comment markers; unified/split; per-file Viewed checkbox with progress bar and reset on change; blue "+" on hover; click the first line number then drag or Shift-click the last; comment box at the last selected line; "Comment" vs "Start a review" then "Add review comment"; pending comments visible only to the author; "Submit review" with summary and Comment / Approve / Request changes; reply box; "Resolve conversation" collapses the thread; Conversations menu (unresolved, resolved, outdated); "Outdated" label, Alt-click Show/Hide outdated; suggested changes (Ctrl/Cmd+G); file-level comment icon in the file header; `C` commit filter; Ctrl/Cmd+Shift+Enter submits a review comment; `J`/`K` next/previous file.
