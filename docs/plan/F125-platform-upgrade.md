# F125 Platform upgrade

Workflow, conventions, and status: [feature track](features.md). From Jan in a session on 2026-11-22: "upgrade all composer dependencies" and "upgrade to frankenphp 1.14 and php 8.6 while you're at it". A known issue, so it goes ahead of [F126](F126-issue-tracker-core.md).

## Goal

- `make composer c="audit"` exits 0, so the step of `.github/workflows/qa.yml` passes.
- Every Composer package is at its newest version that resolves.
- The runtime, PHP 8.6 on FrankenPHP 1.14, is not part of this feature. Xdebug 3.5.3 and php-cs-fixer 3.95.32, the newest releases, refuse PHP 8.6, and the decision below stops on that. The [report](reports/F125.md) holds the evidence and the facts for that step, which continues as its own entry.

## Depends on

- Nothing.

## Facts

Verified on 2026-11-22:

- `composer audit --locked` exits 1 on `main`: advisory PKSA-s52d-n7sx-trsg, `mcp/sdk` >=0.8.0 <0.9.0, in `OAuthProxyMiddleware` and `ClientRegistrationMiddleware`. The app uses neither: `StreamableHttpTransport::defaultMiddleware()` holds only the CORS and DNS rebinding middleware, and `src/` and `config/` do not name them.
- PHP 8.6.0 is stable since 2026-11-19. FrankenPHP 1.14.0 is stable since 2026-11-21 and makes PHP 8.6 its default. The image tag `dunglas/frankenphp:1.14.0-php8.6-trixie` exists.
- The FrankenPHP 1.14.0 release notes: "Like the official PHP images, the PHP 8.6 Docker images no longer include `mbstring` nor PECL."
- `symfony/mcp-bundle` 0.15.0 requires `mcp/sdk` `^0.9`. `mcp/sdk` 0.10.0 exists and no bundle release accepts it.
- `composer outdated --locked --direct` lists 22 packages. Above a patch or minor step: `mcp/sdk` 0.8.1 to 0.10.0, `symfony/mcp-bundle` 0.14.1 to 0.15.0, `symfony/mercure-bundle` 0.5.0 to 0.7.0, `symfony/ai-mate` and its two extensions 0.14.0 to 0.15.0. The exact pins `playwright-php/playwright` 1.5.0 and `symfony/reprise` 1.3.0 have 1.6.0 and 1.4.0. No Symfony 8.2 release exists.
- The `mcp/sdk` 0.9.0 release notes list as breaking: `structuredContent` is validated against `outputSchema`, and a mismatch answers `isError: true`; ids of requests to the client are UUIDs; a POST is answered with its own responses instead of the outgoing queue of the session; `DnsRebindingProtectionMiddleware` changed; the default clients negotiate `2026-07-28`.
- PHP 8.6 changes the session INI defaults: `session.use_strict_mode` 1, `session.cookie_httponly` 1, `session.cookie_samesite` "Lax". `trim()` trims the form feed by default.

Not checked. Verify each one first, and report a blocker instead of working around it:

- The Mercure version of the FrankenPHP 1.14.0 hub. The summary of the 1.13.5 notes named Mercure 1.1.6.
- An Xdebug release for PHP 8.6 through `install-php-extensions`.
- php-cs-fixer, PHPStan, and PHPUnit on PHP 8.6.
- `symfony/mercure-bundle` 0.7.0 against the hub of the image.
- The service IDs `mcp.server.slopmower.controller` and `mcp.server.slopmower.session.store` in `symfony/mcp-bundle` 0.15.0.
- The Playwright version that `playwright-php/playwright` 1.6.0 needs.

## Decisions

- `mcp/sdk` goes to the newest 0.9 release, `symfony/mcp-bundle` to 0.15.0. Both stay exact pins. `mcp/sdk` 0.10 waits for a bundle release.
- npm packages change only where a Composer package forces it: `@symfony/reprise` with `symfony/reprise`, and `@playwright/test` with `PLAYWRIGHT_VERSION` when `playwright-php/playwright` 1.6.0 needs a newer Playwright.
- If the hub is Mercure 1.x, the change of [README](README.md#known-gotchas) "Mercure 1.x" goes in as one step: `protocol_version`, the JWT claims, the `issuer` blocks, the cookie name.
- A package that has no release for PHP 8.6 stops the feature. No `--ignore-platform-req`, no fork, no development branch.

## In scope

- `composer.json`, `composer.lock`, `symfony.lock`, and the recipe changes that the updates bring.
- `src/Mcp/`, `tests/Support/Mcp/`, `config/packages/mcp.yaml`, `config/routes/mcp.yaml`: the changes that `mcp/sdk` 0.9 and the bundle 0.15.0 need.
- `config/packages/mercure.yaml`: the protocol version of the hub in the image.
- Code that a new package version breaks or deprecates.
- `package.json` and `package-lock.json` for the two coupled packages.
- The version text: "Verified versions", "Definition of done" item 5, and "Known gotchas" of [README](README.md); `docs/specs/mcp.md` section 1; `docs/deployment/`; `docs/developer-guide.md` where it names a version.

## Out of scope

- A general npm upgrade.
- `mcp/sdk` 0.10.
- MCP resources and prompts.
- The production deployment.

## Acceptance tests

- `make composer c="audit"` exits 0.
- `make composer c="outdated --direct --strict"` lists only `mcp/sdk`.
- Every test of the MCP suite passes unchanged in what it asserts. A test changes only where the protocol of `mcp/sdk` 0.9 differs, and the report names each one.
- `HubIsolationTest` passes.

## Verification

- `make check` with `make act`, and `make prod-check`.
- On the main stack after the merge: the sign-in page answers 200, a live update reaches an open review page, and `tools/list` over `/_mcp` returns the 18 tools.
- `check-main.sh F125` after the merge.
