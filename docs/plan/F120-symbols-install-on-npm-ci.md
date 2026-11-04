# F120 Symbols packages on npm ci

Workflow, conventions, and status: [feature track](features.md). Reported by Jan on 2026-11-05: the Forge deployment of `beta.slopmower.app` fails since [F117](F117-browser-only-code-access.md).

## Goal

`npm ci && npm run build` in a fresh checkout builds the SPA, as before F117.

## Facts

Verified on 2026-11-05 on `main` at `7f4797b4`:

- `assets/code/outlineWorker.ts` imports `web-tree-sitter` and the grammar `.wasm` files from `symbols/node_modules/` (D114).
- Only the Makefile target `symbols/node_modules/.package-lock.json` and the `assets` stage of the `Dockerfile` run `npm ci --prefix symbols`. The `postinstall` of the root `package.json` runs only `bin/patch-react-router.mjs`.
- In a fresh worktree, `npm ci && npm run build` fails with `[UNRESOLVED_IMPORT] Could not resolve '../../symbols/node_modules/web-tree-sitter/web-tree-sitter.wasm?url'`, the error of the Forge log.

## Decisions

- The root `postinstall` also runs `npm ci --prefix symbols --ignore-scripts --no-audit --no-fund`. `--ignore-scripts`, because the install scripts of the grammar packages compile Node bindings that the `.wasm` grammars do not need.
- The `assets` stage of the `Dockerfile` drops its own `npm ci --prefix symbols`. The image build then runs the same two commands as a host deployment, so the prod image check of CI fails when the `postinstall` stops installing `symbols`.
- The Makefile target stays: it installs again when `symbols/package-lock.json` changes without a root `npm ci`.

## In scope

`package.json`, `Dockerfile`, `docs/developer-guide.md`.

## Out of scope

- npm workspaces: they hoist the grammar packages out of `symbols/node_modules/`, where the worker and the `symbols` image expect them.
- The Forge deploy script, which is not in the repository.

## Acceptance tests

- A fresh worktree: `npm ci --no-audit --no-fund && npm run build` exits 0, and `public/build/` holds `web-tree-sitter-*.wasm`.
- The `Dockerfile` build of `make check` (`make act`, the prod image check) passes without the second `npm ci`.

## Verification

- `make check` green.
- Jan: the next Forge deployment of `beta.slopmower.app` passes `npm run build`.
