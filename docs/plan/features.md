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
| F01 | [General requests](F01-general-requests.md) | In progress | | |
| F02 | [Focus mode](F02-focus-mode.md) | Not started | | |
| F03 | [Line wrapping](F03-line-wrapping.md) | Not started | | |
| F04 | [Code navigation](F04-code-navigation.md) | Not started, open questions | | |

## Candidates

Ideas from review sessions, not scheduled yet. Once F01 is merged, each can also be opened as a general request in Holistic Review itself.

- **Quick open.** Find a file or a symbol of the review commit by name and open it, from the file tree data (V6) and the symbol index. Split from F04.
- **One scroll area in the normal file view.** Outside focus mode, the window, the file tree, and the code view each scroll. Split from F02.
