# F65 Known issues: docs anchors, skill copy, missed unsuspend

Workflow, conventions, and status: [feature track](features.md). On 2026-10-19, the onboarding planning found three open known issues outside the UI. The hard rule "bugs and issues before features" puts them before F66 to F71. F71 relies on the heading anchors.

## Goal

- A `<slug>.md#fragment` link in `docs/user` jumps to its heading.
- The project skill and the public skill are the same text.
- A sign-in that clears a stored suspension repairs the linked pull requests, as an `unsuspend` delivery does.

## Facts

Verified on 2026-10-19:

- **Anchors:**
  - `reports/M23.md:51`: "CommonMark adds no heading IDs, so a `<slug>.md#fragment` link keeps its fragment but does not jump."
  - Two links use a fragment now: `getting-started.md:15` (`reviewing.md#the-slopmower-check`) and `reviewing.md:98` (`#ask-for-a-plan-first`).
  - `league/commonmark` 2.10.3 ships `HeadingPermalinkExtension`. With `insert: none`, it sets the heading `id` only with `apply_id_to_heading: true` (`HeadingPermalinkProcessor.php:66-87`).
  - The default `SlugNormalizer` gives GitHub-style slugs, and repeated slugs get `-1`, `-2`.
  - `twig/extra-bundle` passes `twig_extra.commonmark` to the converter, and that node accepts extra keys. Tagged extensions (`twig.markdown.league_extension`, `config/services.yaml:47-49`) are added after it.
  - `markdown_to_html` is used only in `templates/docs/page.html.twig:4`.
- **Skill:**
  - `reports/F58.md:75`: `.claude/skills/slopmower/SKILL.md` lacks `start_review`, `suggest_request`, the `suggestion?` filter, and section 6 of `docs/user/skill/SKILL.md`.
  - On 2026-10-19 it has 0 matches of `start_review|suggest_request`, and the public skill has 5.
- **Unsuspend:**
  - Candidate "Pull request states after a missed unsuspend" (F56 report).
  - `InstallationSync::record()` (`src/Application/InstallationSync.php:144`) deletes and re-creates the installation rows from the listing, and `addedPermissions()` compares the stored rows with the listed ones.
  - The `unsuspend` handler calls `QueuePullRequestRefresh::ofInstallation($id, readEvery: true, checkRuns: true)` (`GitHubInstallationHandler.php:131`). A sign-in whose listing clears `suspendedAt` queues nothing.

## Decisions

- **Anchors:**
  - Register `League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension` with the tag `twig.markdown.league_extension`, next to the GFM extension.
  - In `config/packages/twig_extra.yaml`, under `commonmark`, set `heading_permalink: {insert: none, apply_id_to_heading: true, id_prefix: '', fragment_prefix: ''}`.
  - The visible heading text does not change.
- **Skill:**
  - `.claude/skills/slopmower/SKILL.md` gets the text of `docs/user/skill/SKILL.md`.
  - A test keeps them equal.
- **Unsuspend:**
  - `record()` also returns the IDs of the installations whose stored row had `suspendedAt` and whose listed row has none.
  - `sync()` calls `ofInstallation($id, readEvery: true, checkRuns: true)` for each. An installation in both sets is queued once, with the unsuspend arguments.

## Depends on

M23 (docs), F56 (unsuspend refresh).

## In scope

- `config/services.yaml`, `config/packages/twig_extra.yaml`.
- `.claude/skills/slopmower/SKILL.md`.
- `src/Application/InstallationSync.php`.

## Out of scope

- Visible permalink symbols next to the headings.
- A table of contents, which is part of F71.

## Acceptance tests

- **Functional** `DocsRenderingTest`:
  - `/docs/reviewing` has `#the-slopmower-check` and `#ask-for-a-plan-first`.
  - Every `a[href*="#"]` of every docs page points to an `[id]` that exists on its target page.
- **Functional** `SkillMatchesToolsTest`: the project skill equals `docs/user/skill/SKILL.md`.
- **Functional:** a sign-in whose listing clears the `suspendedAt` of a stored installation queues the reads of every linked pull request and the check runs, as `unsuspend` does. A listing that keeps the suspension queues nothing.

## Verification

- `make check` is green.
- `/docs/getting-started` on the dev stack: the link to the Slopmower check jumps to its heading.
