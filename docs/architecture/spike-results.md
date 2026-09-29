# Spike results

Part of [the architecture document](../architecture.md). Principle B: one throwaway spike per UI component. All spikes used the same samples: Symfony `Command.php` (733 lines) and Doctrine `UnitOfWork.php` (3534 lines), Vite 8.3.1, React 19.3.0, TypeScript 7.0.2, and puppeteer-core with real mouse events against headless Chrome 153.

The phase 1 spikes, clones, transcripts, and most screenshots were in a temporary scratch directory. They are not in the repository. The screenshots that the main document uses are in this directory. Paths such as `spikes/...` or `tools/...` refer to that scratch directory.

## Spike: @pierre/diffs 1.5.1 (extra candidate, not in the brief)

Apache-2.0, (c) 2025 Pierre Computer Company. Source https://github.com/pierrecomputer/pierre/tree/main/packages/diffs (npm metadata has no repository field). Docs https://diffs.com/docs. 1.5.1 published 2026-09-25; first stable 1.0.0 on 2025-12-12; 63 versions in the last 6 months. Breaking changes ship in minor versions (1.2 removed `enableHoverUtility`; 1.3 changed prop types; 1.4 editor API; 1.5 error handling). 6,229 stars, 360 commits on `packages/diffs` in 6 months. Spike: `spikes/pierre-diffs`, real mouse, production build.

| Requirement | Result | Evidence and workaround |
|---|---|---|
| R1 full unchanged file | PASS | Native non-diff `File` component; all rows `data-line-type="context"`. Phantom row 734 for a trailing newline (`computeFileOffsets.ts:87-99`); fix: public `cleanLastNewline()` (1 line). |
| R2 line numbers | PASS native | One line-number column (`FileRenderer.ts:922,937-939`). |
| R3 PHP highlighting | PASS native | Shiki 4.4.3, themes `github-light-default` / `github-dark-default`. Grammars load lazily. |
| R4 hover "+" | PASS native | `enableGutterUtility` + `onGutterUtilityClick` render `button[data-utility-button]` (`createGutterUtilityElement.ts:5-15`). Gap G1: while a selection exists, "+" stays on the last selected line (`InteractionManager.ts:1164-1191`); glue clears the selection after submit or cancel (15 lines). |
| R5 "+" opens widget below line | PASS | Annotation slot `annotation-<line>` (`getLineAnnotationName.ts:3-7`). Focus needs a wait for the slot (28 lines). |
| R6 range select | PASS native gestures | Drag on "+", drag on numbers, click + shift-click (`InteractionManager.ts:833-858,889-923`). Glue: open the widget below the bottom line (about 25 lines). |
| R7 programmatic range | PASS for `File` and `CodeView` | `selectedLines` prop + annotation + scroll. `CodeView` ref `scrollTo({type:'line', lineNumber, align})` works on 3,534 lines. FAIL only for `File` inside `Virtualizer` (no line scroll API). |
| R8 several threads | PASS | Any React content; Primer `Textarea`/`Button` work in slots (light DOM, global CSS applies). Needs a `BaseStyles` wrapper or widgets inherit the code font. One annotation per line (slot names collide); keep the annotations array identity stable or every keystroke re-renders (108 ms long task per key before the fix, 0 after). |
| R9 large file | PASS, virtualises | UnitOfWork.php: `CodeView` + worker pool first rows 41 ms, fully highlighted 364 ms, **0 main-thread long tasks**, 49 rendered rows. `File` + `Virtualizer` + workers: 51 ms, 0 long tasks. Worker pool is marked experimental. |
| R10 GitHub look | PASS | GitHub themes from Shiki; CSS variables for font, line height, selection colour, "+" colour. |

Shadow DOM: open roots (`src/components/File.ts:1363`), so php-webdriver `getShadowRoot()` (1.16.0, W3C mode) reaches them in Panther tests. `FileDiff` also exists for diff rendering (verification view).

Totals: about 72 lines of workarounds; all review glue 456 lines including widget and thread state. Bundle: core 392 kB / 113 kB gzip, `File` +1.7 kB gzip, `CodeView` +60 kB gzip; PHP grammar lazy chunks 87 kB gzip. Apache-2.0 obligations on distribution: include the license, keep notices, reproduce the `@pierre/theme` NOTICE (credits primer/github-vscode-theme, MIT).

## Spike: @git-diff-view/react 0.1.7

MIT. 0.1.7 released 2026-07-13. 742 stars, 527 of 530 commits by one maintainer. React 19.3, Vite 8.3, TypeScript 7.0 in the spike. Tests with puppeteer-core and real mouse events. Spike: `spikes/git-diff-view`.

| Requirement | Result | Evidence and workaround |
|---|---|---|
| R1 full unchanged file | PASS with workaround; native FAIL | Identical content gives zero hunks and 0 visible lines (upstream issue #16, "wontfix"). `onAllExpand` changes nothing (`core/src/diff-file.ts:1413-1428`). W1 (11 lines): one hand-built hunk `@@ -1,733 +1,733 @@` with every line as context. |
| R2 line numbers | PASS | 733 rows match byte for byte. Unified mode shows old and new numbers; W2 (15 lines CSS) hides the old column through internal markup. |
| R3 PHP highlighting | PASS, gotcha | lowlight (highlight.js, all languages). Files above 2000 lines silently lose all highlighting (`lowlight/src/index.ts:72`); W6 (1 line) `setMaxLineToIgnoreSyntax(10_000)`. |
| R4 hover "+" | PASS native | Only on lines inside a hunk, so it needs W1. |
| R5 "+" opens widget below line | PASS native | One open native widget at a time (`react/src/components/tools.ts:121-138`). |
| R6 range select | PARTIAL | `DiffViewWithMultiSelect` (not documented) highlights a drag natively. Bug B1: the widget does not open at drag end, and the intended "select then press +" flow loses the range (`core/src/multiSelect/manager.ts:101` clears it). Bug B2: a drag that starts on "+" selects nothing. No shift-click. W3 (8 lines) opens the widget on `onMultiSelectComplete`; W4 (27 lines) adds shift-click. |
| R7 programmatic range | PASS with W5 (9 lines) | `setPreselectedLines({new:[231,286]})` + widget store `setWidget` at 286 + `scrollIntoView`. |
| R8 several threads | PASS | Saved threads through `extendData` + `renderExtendLine`. Open drafts must also go into `extendData`. |
| R9 large file | PARTIAL | No virtualisation (issue #21 open since 2024-12). UnitOfWork.php (3534 lines): first render 503-511 ms, 60,947 DOM elements, 72.8 MB heap, 100-130 ms long tasks per interaction. |
| R10 GitHub look | PASS | Built-in light and dark palettes close to GitHub; about 60 lines of CSS for fonts and range colour. |

Totals: about 77 lines of glue (62 TypeScript, 15 CSS), all through public props, ref methods, an undocumented widget-store hook, and internal DOM attributes. Bundle: library 1,058 kB min / 336 kB gzip, of which highlight.js with all languages is 905 kB / 299 kB (issue #58). Vue, Svelte, and Solid variants have the same features.

## Spike: react-diff-view 3.3.3

MIT. Last release 2026-03-30 (3.3.2 in 2025-07, 3.3.1 in 2024-12). 1,016 stars, 10 open issues. Maintenance-level activity. React 19.3 works (peer `react >=16.14.0`, 0 StrictMode warnings). Spike: `spikes/react-diff-view`, 17 real-mouse checks, 17 pass.

| Requirement | Result | Evidence and workaround |
|---|---|---|
| R1 full unchanged file | PASS | `textLinesToHunk(lines, 1, 1)` (documented, README.md:569) or a synthetic `@@ -1,733 +1,733 @@` diff through `parseDiff`. 733 `normal` rows, no +/- characters. |
| R2 line numbers | PASS | `viewType="split"` + `diffType="add"` shows ONE gutter column with no CSS (undocumented "monotonous" mode, `src/Diff/index.tsx:123,137-143`). Unified mode always renders two gutters. |
| R3 PHP highlighting | PASS with adapter | refractor 5.0.0 (Prism PHP grammar). README says refractor 4+ is not supported; v5 needs a 3-line adapter (`highlight(c,l).children`). Types do not catch a wrong adapter (bug B1). |
| R4 hover "+" | PASS | Native `renderGutter({inHoverState})`; button markup is ours (19 lines + 25 lines CSS). |
| R5 "+" opens widget below line | PASS | `widgets` prop keyed by `getChangeKey`. |
| R6 range select | PASS, custom | No native range selection (issue #185 open since 2023). `useLineRangeSelection.ts`, 91 lines, on `gutterEvents`, `codeEvents`, `selectedChanges`. Drag down, drag up, fast drag with no gaps, click + shift-click all pass. |
| R7 programmatic range | PASS | 9 lines API + 6 lines scroll. |
| R8 several threads | PASS | One widget node per line; an array of threads per line (17 lines). |
| R9 large file | PARTIAL | No virtualisation (issues #237, #102 open). UnitOfWork.php: first paint 416-472 ms; `tokenize` 218-250 ms, of which about 90% is react-diff-view's own code (bug B3); 28,381-50,862 DOM elements. `useTokenizeWorker` exists, not tested. |
| R10 GitHub look | PASS, our CSS | 348 lines of CSS with Primer light/dark tokens. Lines always wrap (`word-break: break-all`, issue #238); a 3-rule CSS probe gives GitHub-like no-wrap. |

Bugs: B1 types import a refractor 3 export (wrong adapter compiles, fails at runtime); B2 sibling token nodes never merge (`src/tokenize/backToTree.ts:4-19`, typo `'chlidren'`), 4,480 wrapper spans in a 733-line file; B3 tokenize copies arrays per token and highlights both sides; B4 empty lines render as a space; B5 `esm/` build loads only in bundlers. Keyboard users cannot reach the hover "+" without extra work. Prism emits bare global class names (`comment`, `string`), so our CSS classes need a namespace.

Totals: about 175 lines of glue (full-file hunk, adapter, "+" button, range selection, several threads, scroll, API) plus 348 lines of GitHub CSS. Bundle: library 58 kB / 19.5 kB gzip, refractor + PHP 40 kB / 14.5 kB gzip.

## Spike: @primer/react 38.40.0

MIT. `@primer/react` 38.40.0 (2026-09-18), `@primer/primitives` 11.10.0, `@primer/octicons-react` 19.38.0, `@primer/css` 22.3.2. React 19 supported (peer `18.x || 19.x`; `react-is` is a required peer). Spike: `spikes/primer` (static mock, no back end).

**What it gives.** TreeView (beta) for the file tree with counts and "viewed" marks; ActionMenu, ActionList (beta); Button, IconButton, Textarea, Checkbox, Radio, FormControl, Label, CounterLabel, Avatar, RelativeTime, ProgressBar, SegmentedControl, AnchoredOverlay, PageLayout, Stack (alpha); DataTable and Table (alpha, `@primer/react/experimental`). No component is "stable": 56 alpha, 6 beta, 7 draft, 11 deprecated. Alpha is "ready for production use", with breaking changes possible (https://primer.style/product/contribute/adding-new-components).

**What it does not give.** No public comment-thread box, no MarkdownEditor (internal to GitHub), no Markdown renderer. `StateLabel` has fixed issue/PR states only. The spike composed the thread box from Avatar, Link, RelativeTime, ActionMenu, Label, Textarea, Button, and Stack plus about 250 lines of layout CSS; Markdown with `marked` + `.markdown-body` from `@primer/css/dist/markdown.css` (needs sanitising). Status chips: `Label` variants with octicons for open, in progress, done, verified, reopened, won't do, Outdated, Pending.

**Styling.** v38 uses CSS Modules; styled-components, `sx`, and `Box` were removed in 38.0.0 (`CHANGELOG.md:1064-1070`). `ThemeProvider` (from `@primer/react/next`; the root export is deprecated) and `BaseStyles` are required. Import `@primer/primitives` theme files `light.css` and `dark.css` (mandatory), `motion.css`, and optionally size/typography files. `@primer/css` is in KTLO mode (README line 10); we need only its `markdown.css`. Copy the theme attributes to `<html>` for the dark body background.

**Shadow DOM.** Primer components inside a shadow root lose all styles (button 21 px, browser default background). Fix: adopt the document stylesheets as constructed `CSSStyleSheet`s into `shadowRoot.adoptedStyleSheets` (about 400 kB CSS per root if cloned; share one set across roots in production). Menus opened from inside a shadow root mount in the light DOM portal and work (focus, Escape, arrows).

**Bundle.** Primer JS about 393 kB / 129 kB gzip; Primer component CSS 211 kB / 28 kB gzip; primitives + markdown CSS 192 kB / 32 kB gzip.

**Problems.** Docs and types disagree on `ThemeProvider`; primitives README omits `radius.css`; overlays fade in over 0.2 s (E2E tests must wait); no stable components; `DataTable` only in `/experimental`.
