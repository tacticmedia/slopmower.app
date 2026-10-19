# F70 Review tour

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-19 for a tour guide of the review screen. It highlights browse files, open a file, find a symbol, add a comment, and go to another location.
- The tour is on by default for every user.
- A user can dismiss it, and can turn it on again in Settings.

Jan decided:
- the state lives per account, not per browser;
- "go to another location" means Go to declaration.

## Goal

A first review teaches its key actions in place, one short part at a time. The account remembers what the user finished or dismissed.

## Facts

Verified on 2026-10-19:

- **User storage:**
  - `src/Entity/User.php` (table `app_user`, `TenantOwned`) has no preference field. No per-user setting exists on the server.
  - Users are not event-sourced. R8 and A2 write with a plain Doctrine flush.
- **Session copy:** `TenantUserProvider::refreshUser()` reloads the user on every request, so the serialized session copy only seeds the identifier. `User` implements neither `EquatableInterface` nor a password interface.
- **A1:** `MeController` gets a session user through `#[CurrentUser]` and loads a bearer caller's user by ID. `me.schema.json` requires `user`, `tenantId`, `caller`, `token`, `features`, `plan`, `usage`, `links`, with the fixtures `me.agent.json` and `me.local-user.json`.
- **Session-only actions:** they use `#[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]` with `CurrentActor::sessionUser()` (A2 `AccountController.php:36`, R2). An agent gets 403 `forbidden`.
- **Set conventions:**
  - Wire enum values are snake_case.
  - A set that grows is an open set, `anyOf [{enum}, {type: string}]` like `UsageLimit` (`README.md:184`).
  - D3 uses PATCH with "absent or null keeps".
- **Tests:**
  - Inventory tests: `CrossTenantCases.php`, `CrossTenantRouteInventoryTest`, `CrossTenantCoverageTest`, `ActorKindTest::sessionOnlyEndpoints()`, `InputFixturesTest`, `EnumContractTest`.
  - `MeEndpointTest:47-60` compares the whole A1 body.
- **Tour targets** (light DOM unless noted):
  - the tree `[data-testid="file-tree"]`;
  - the table `aria-label="Folders and files"`;
  - the buttons "Quick open" (Mod+K), "Agent prompts", and "Symbols";
  - the "Requests" item of `UnderlineNav aria-label="Review"`;
  - the "Go to declaration" input (PHP only);
  - the code in the open shadow root of `diffs-container`: rows `[data-line]`, the gutter "+" `[data-utility-button]` in `[data-column-number]`.
- **Sticky parts:** the file header is sticky (`FileHeader.tsx`), and the window scrolls the code browser (D55).
- **Library** `react-joyride` 3.2.0 (2026-07-09, MIT):
  - peers `react` and `react-dom` 16.8 to 19;
  - ESM with types, no CSS;
  - targets are a selector, an element, a ref, or a function;
  - `tooltipComponent` renders a custom tooltip;
  - its position check does not see a sticky ancestor outside a shadow root.

## Decisions

D77, preferences:
- **A4** `PATCH /api/v1/me/preferences`, session only.
  - Body `PreferencesInput {completedTours?: Tour[] | null}`: a list replaces the stored one, and absent or null keeps it.
  - 200 `me.schema.json#/$defs/Preferences {completedTours}`.
  - 422 `invalid_input` for an unknown name or a duplicate.
  - No event and no live update.
- **`Tour`:** an open set in `common.schema.json` with `code_browser` and `file`, listed with the open sets of `README.md:184`. PHP `App\Entity\Tour`, TS `TOURS`.
- **A1** gets the required `preferences`.
- **Storage:** `User::$completedTours` is a jsonb `list<string>` with the default `'[]'`, so every user, the existing ones too, starts with the tour.
  - The getter maps with `Tour::tryFrom` and drops unknown names.
  - The setter stores the names in the order of `Tour`.
  - The tenant purge already deletes `app_user`.

D78, tour:
- `react-joyride` 3.2.0, exact pin, with a `tooltipComponent` built from Primer `Heading`, `Text`, `Button`, and `Link`. The tooltip shows "n of m", Back, Next or Done, and "Skip tour".
- **Part `code_browser`** starts when the Code tab shows a directory and the tree has rendered.
  1. File tree: "Browse the files of the pinned commit."
  2. "Folders and files": "Open a file here or in the tree."
  3. Quick open: "Find a file or a symbol by name, with Cmd+K or Ctrl+K."
  4. Requests tab: "Each comment is a change request. Follow them here."
  5. Agent prompts: "Give the review to your agent." Without a token it links to Settings.
- **Part `file`** starts when a file view has its first `[data-line]` row.
  1. The line number of the first rendered line, through a function target into the shadow root: "Click + to comment. Drag over line numbers for a range."
  2. Symbols: "Find a symbol in this file."
  3. Go to declaration (the code view, or the PHP input): "Cmd-click or Ctrl-click a name to go to its declaration. Back returns."
- Steps target elements in normal flow, not the stuck header.
- **Ending a part:**
  - "Done", Close, and Escape mark the current part.
  - "Skip tour" marks both.
  - A route change away from the directory during `code_browser` marks it.
- **Writes:** a fetcher posts to the action-only root route `preferences`, which calls A4. The root loader revalidates `me`. The route is never navigated, so it needs no shell route.
- **Settings:** a "Review tour" section with the ToggleSwitch "Show the review tour". It is on while a part is not completed. On sends `[]`, off sends both.

## Depends on

F64.

## In scope

- **Specs:**
  - `docs/specs/rest-api.md`: the A4 row and section, A1 bullets, and the inline example at lines 114-125.
  - `common.schema.json`, `me.schema.json`, `inputs.schema.json`.
  - The fixtures `me.agent.json` and `me.local-user.json`, and the new `me.preferences.json` and `inputs.preferences.json`.
  - `README.md`: the open sets, the amendment line of section 8.10, and the fixture rows of section 11.
- **Architecture:** D77 and D78 rows, and the User row at line 161.
- **Back end:**
  - `Tour`, the `User` field, a migration through `make migration`;
  - `Preferences` in `Application\Query`, used by A1 and A4;
  - `PreferencesInput`, and the A4 action in `MeController`.
- **Front end:**
  - `react-joyride` 3.2.0 in `package.json`, and in the verified-versions table of the plan README;
  - `assets/tour/` (`ReviewTour.tsx`, `TourTooltip.tsx` with a CSS module, `tourSteps.ts`, `useTours.ts`), mounted in `CodeLayout`;
  - the root `preferences` action;
  - the Settings section.
- **TS:** `Tour` and `TOURS` in `common.ts`, `Preferences` in `me.ts`, `PreferencesInput` in `inputs.ts`, `contract-enums.test.ts`, `contract-fixtures-type.test.ts`.
- **Test support:**
  - `MockApi` answers A4 and updates its `me`.
  - `me.local-user.json` holds both tours as completed, so other UI tests never meet the tour.
  - The full-stack `signInAs()` marks both tours as completed for its user.
- **Docs:** `docs/user/privacy.md:21-35` (a row for the completed tours), and `docs/user/reviewing.md` (the tour and the switch).

## Out of scope

- Tours of other screens.
- A per-browser state.
- Analytics of the tour.

## Acceptance tests

- **Functional** `PreferencesTest`:
  - A4 replaces the list;
  - a null or absent list keeps it;
  - an unknown name and a duplicate give 422;
  - a second `GET /api/v1/me` in the same session returns the new list;
  - a bearer caller gets 403.
- **Functional:**
  - `MeEndpointTest` has `preferences`;
  - `CrossTenantCases` has `api_me_preferences`;
  - `ActorKindTest` lists A4;
  - `InputFixturesTest` and `EnumContractTest` cover the new input and `Tour`.
- **UI** `ReviewTour.spec.ts`:
  - with `[]`, the `code_browser` steps show in order;
  - Done sends `["code_browser"]`;
  - "Skip tour" sends both;
  - the `file` part starts on the first file view and points at the gutter;
  - Escape marks the part;
  - the focus moves into the tooltip and returns;
  - with both parts completed, no tour shows.
- **UI** `SettingsTour.spec.ts`: the switch shows the state, and each change sends A4.

## Verification

- `make check` is green.
- `make journeys`: "Tour, code browser" and "Tour, file".
- On the dev stack: walk both parts, skip, reload (no tour), then turn the tour on in Settings (the tour shows).
