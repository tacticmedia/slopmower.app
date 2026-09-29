# M17 Front-end shell

Workflow, conventions, and status: [README](README.md).

## Goal

A React 19 single-page app through Reprise and Vite, with React Router 8.4.0 in Data Mode, Primer, and API types checked against the contract. Decision D29.

## Depends on

M10, M11.

## In scope

- `composer require symfony/reprise:1.3.0`, an exact pin. The recipe re-creates `assets/app.js` and edits `templates/base.html.twig`. Delete `assets/app.js` and keep one shell template.
- npm on the host with `--save-exact`, versions from the [verified versions](README.md#verified-versions) table:
  - runtime: `@symfony/reprise` 1.3.0, `react` and `react-dom` 19.3.0, `react-router` 8.4.0, `@primer/react` 38.40.0, `@primer/primitives` 11.10.0, `@primer/octicons-react` 19.38.0, `@primer/css` 22.3.2 for `markdown.css`, `react-markdown` 10.1.0, `remark-gfm` 4.0.1;
  - development: `vite` 8.3.1, `@vitejs/plugin-react` 6.1.1, `typescript` 7.0.2, `vitest` 5.0.2, `@types/react` and `@types/react-dom` 19.3.0.
- Check the peer ranges after the install. React Router 8.4.0 needs `react` and `react-dom` 19.2.7 or later. If a peer range excludes React 19.3.0, stop and report.
- `vite.config.ts` with the entry `app: assets/app.tsx`.
- `package.json` scripts:
  - `dev`, `build`;
  - `build:e2e`, which writes to `public/build-e2e`. Confirm the Reprise keys for the `e2e` environment with `make console c="config:dump-reference reprise"`;
  - `typecheck`: `tsc -b` in strict mode;
  - `test`: `vitest run`.
- `ShellController`: one route per shell path of docs/specs/rest-api-requests.md section 7, with the requirement `.+` for `{path}` of `/reviews/{id}/files/{path}`. It needs a signed-in user. Other paths are not captured, so no exclusion list is necessary.
- Confirm how Reprise renders its tags in the `test` environment. If it needs a manifest, point `test` at `public/build-e2e`, and let `make test` run `npm run build:e2e` when that build is missing. `ShellRouteTest` renders the shell, and `make check` runs `test` before `e2e`.
- Front-end code:
  - `assets/app.tsx`: `ThemeProvider`, `BaseStyles`, and the light, dark, and motion CSS of `@primer/primitives`;
  - `assets/router.tsx`: `createBrowserRouter`. The root `loader` calls `GET /api/v1/me`. An `errorElement` shows errors;
  - `assets/api/client.ts`: same-origin `fetch` with credentials. Writes send the CSRF proof of the M10 protocol. Errors become an `ApiError` with `code`, `message`, and `details`;
  - `assets/api/types/*.ts`: written from `docs/specs/schemas/`. Enums are `as const` arrays;
  - a `RootLayout` with a Primer header and navigation;
  - CSS modules for our layout CSS.

## Out of scope

- Screens: M18 and later.
- The Mercure hook: M24.

## Files (representative)

- `vite.config.ts`, `tsconfig.json`, `package.json`, `package-lock.json`.
- `templates/shell.html.twig`, `templates/base.html.twig`, `src/Controller/ShellController.php`, `config/packages/reprise.yaml`.
- `assets/app.tsx`, `assets/router.tsx`, `assets/api/client.ts`, `assets/api/types/*.ts`, `assets/layout/RootLayout.tsx`, `assets/layout/*.module.css`.
- `assets/api/*.test.ts`, `tests/Functional/ShellRouteTest.php`, `tests/E2E/ShellTest.php`.

## Tasks (ordered)

1. Install Reprise and clean up what its recipe changed.
2. Install the npm packages and check the peer ranges.
3. Write the Vite, TypeScript, and package configuration.
4. Write the shell controller and template.
5. Write the router, the API client, and the types.
6. Write the tests. Check that `make front` and `make e2e` run npm, because `package.json` exists.

## Acceptance tests

- **V `ApiClientTest`** proves the client behaviour.
  - "Maps an Error body to ApiError with code and details"
  - "Sends the CSRF proof only on writes"
- **V `ContractEnumsTest`** proves that the TypeScript enums follow the schemas.
  - "Each enum array equals its enum in docs/specs"
- **V `ContractFixturesTypeTest`** proves that the TypeScript types fit the real payloads. It is a type-level check that `tsc -b` compiles. TypeScript types an imported JSON file with `string` in place of string-literal unions, so the check uses each DTO type with its string-literal unions widened to `string`.
  - "Each REST fixture satisfies its widened DTO type"
- **F `ShellRouteTest`** proves the catch-all route.
  - "A signed-in user gets the shell page"
  - "An anonymous user is redirected to /login"
  - "/api/v1/x stays a JSON 404"
  - "/docs, /_mcp, and a path outside the shell routes are not captured"
- **E `ShellTest`** proves the app in a browser.
  - "The header shows the login of the signed-in user"
  - "Client-side navigation keeps a window marker"
  - "Client-side navigation to an unknown path shows the error boundary"

## Verification commands

```sh
npm ci
npm run typecheck
npm test
npm run build:e2e
make e2e
make check
```

By hand: `npm run dev` gives hot reload at `http://localhost:8000`.

## Done

`make check` includes `make front`, and both run npm.
