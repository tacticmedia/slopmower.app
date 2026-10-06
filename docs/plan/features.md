# Feature track

Workflow and conventions: [README](README.md). The proof of concept (M00 to M35) and the [simplification plan](simplification.md) are complete. New product work runs here, one feature at a time.

## Rules

- Hard rule: every feature request is documented in this repository before any code. [AGENTS.md](../../AGENTS.md#hard-rule-every-feature-request-is-documented) states it in full.
- A feature starts as a row in the status table and a scope file `Fnn-<name>.md` in the milestone format: Goal, Depends on, In scope, Out of scope, Acceptance tests, Verification.
- Specs first. A feature that changes a contract amends `docs/specs/` before the code, and records the decision in [architecture section 11](../architecture.md#11-decisions).
- `make check` is green at the end of a feature. One feature, one commit on `main`.
- The implementer writes `reports/Fnn.md` from the [report template](README.md#report-template). The orchestrator owns this table and the scope files.
- A feature updates the docs it makes wrong: `docs/developer-guide.md`, `docs/user/`, the skill, and the plan README conventions.
- An idea that is not scheduled goes to [Candidates](#candidates) or, when it is outside the product scope, to the [backlog](../backlog/README.md).

## Status

| # | Feature | Status | Commit | Files / lines after |
|---|---|---|---|---|
| F01 | [General requests](F01-general-requests.md) | Done 2026-10-07, [report](reports/F01.md) | b589f33 | src 380 PHP / 24850 lines, 380 types; tests 269 PHP / 23064 lines; assets 131 TS / 6668 lines; check 2m12s |
| F02 | [Focus mode](F02-focus-mode.md) | Done 2026-10-07, [report](reports/F02.md) | a1d3a37 | src 380 PHP / 24850 lines, 380 types; tests 269 PHP / 23064 lines; assets 132 TS / 6767 lines; check 2m10s |
| F03 | [Line wrapping](F03-line-wrapping.md) | Done 2026-10-07, [report](reports/F03.md) | 850f9bf | src 380 PHP / 24850 lines, 380 types; tests 269 PHP / 23064 lines; assets 132 TS / 6729 lines; check 2m9s |
| F04 | [Code navigation](F04-code-navigation.md) | Done 2026-10-07, [report](reports/F04.md) | | src 385 PHP / 25332 lines, 385 types; tests 271 PHP / 23304 lines; assets 136 TS / 7036 lines |
| F05 | [Symfony AI Mate for coding agents](F05-symfony-ai-mate.md) | Done 2026-10-07, [report](reports/F05.md) | 86d1a6b | src 380 PHP / 24850 lines, 380 types; tests 269 PHP / 23064 lines; assets 132 TS / 6767 lines; check 2m24s |

## Candidates

Ideas from review sessions, not scheduled yet. Once F01 is merged, each can also be opened as a general request in Holistic Review itself.

- **Quick open.** Find a file or a symbol of the review commit by name and open it, from the file tree data (V6) and the symbol index. Split from F04.
- **One scroll area in the normal file view.** Outside focus mode, the window, the file tree, and the code view each scroll. Split from F02.
- **Say why "Symbols" is disabled.** The button is greyed out for a PHP file that declares nothing, for example `public/index.php`, and gives no reason. A tooltip such as "No symbols in this file" would say why.
- **Project-specific Mate tools.** For example a tool that reads the event log of a review. Split from F05.
