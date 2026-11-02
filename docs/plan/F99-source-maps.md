# F99 Source maps for browser reports

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03, after a browser report showed only minified frames: "promote it and build it", the Candidate "Source maps for browser reports" of the F77 planning.

## Goal

- A browser report of a build that uploaded its source maps shows the file, the line, the function, and the source lines of `assets/`.
- The image serves no source map.
- A build without the Sentry build arguments is unchanged.

## Facts

Verified on 2026-11-03:

- `vite.config.ts` sets no `build.sourcemap`, so the build writes no maps. Nothing uploads.
- The release build is the `assets` stage of the `Dockerfile`. `.dockerignore` excludes `.git`.
- `@sentry/vite-plugin` 11.10.0 of 2026-10-28 is the latest version. It reads `SENTRY_ORG`, `SENTRY_PROJECT`, and `SENTRY_AUTH_TOKEN`, writes a debug ID into each chunk and its map, and uploads them through the CLI `sentry` 0.45.0. In `node_modules/@sentry/bundler-plugins/build/esm/`:
  - Without the token or the organization, it only warns and skips the upload (`canUploadSourceMaps`). An organization auth token does not replace `SENTRY_ORG`: the CLI then fails with "Could not auto-detect organization and project".
  - An upload error only logs, unless `errorHandler` throws.
  - It deletes `sourcemaps.filesToDeleteAfterUpload` in a `finally`, also after a failed upload.
  - It takes the release name from `SENTRY_RELEASE`, CI variables, or `git rev-parse HEAD`, then creates, finalizes, and injects that release. With no name, it sends the string `undefined` as the release of the upload. An empty name skips all three, and the CLI omits an empty release.
  - `telemetry` is on by default: each build sends the errors and timings of the plugin to Sentry.
  - It does not process the bundle of the `@pierre/diffs` worker (`worker-*.js`, `wasm-*.js`). Vite builds a worker with `worker.plugins`.
- BuildKit leaves a secret out of the cache key, and puts an `ARG` that a `RUN` sees into it. A missing secret mounted with `env=` leaves the variable unset.

## Decisions

D100:

- `SENTRY_PROJECT` switches the upload on. Then `SENTRY_ORG` and `SENTRY_AUTH_TOKEN` are required, or the build fails. Without `SENTRY_PROJECT`, the build writes no maps and the plugin does nothing.
- `build.sourcemap: 'hidden'`. The plugin deletes `public/build*/**/*.map` after the upload.
- `errorHandler` throws, so a failed upload fails the build. During a Sentry outage, build without the arguments.
- `release.name: ''`: the build creates, injects, and attaches no release. D86 holds: the SDK takes `APP_RELEASE` at run time.
- `telemetry: false`.
- The `assets` stage declares `ARG SENTRY_ORG` and `ARG SENTRY_PROJECT`, and mounts the secret `SENTRY_AUTH_TOKEN` as a variable for `npm run build`. The arguments are in the cache key, so a build with them never reuses a cached build without them.
- `bin/prod-check` fails when `public/` of the image holds a `.map` file.

## Depends on

Nothing. It extends F77 (D86).

## In scope

- `vite.config.ts`, `package.json`, `package-lock.json`: `@sentry/vite-plugin` 11.10.0, a dev dependency.
- `Dockerfile`: the `assets` stage.
- `bin/prod-check`, the help line of `make prod-check`.
- `docs/deployment/README.md`, `docs/deployment/dockerised.md`, `docs/architecture.md` (D100), `docs/plan/README.md` (verified versions).

## Out of scope

- Releases in Sentry: release creation, commits, and deploys.
- Source maps of the `@pierre/diffs` worker. The SDK does not run in the worker.
- A filter for network errors such as `TypeError: Load failed`: Candidate.
- An upload in the QA workflow. It builds without the arguments.

## Acceptance tests

- `bin/prod-check`, in `make prod-check` and the QA workflow: `public/` of the image holds no `.map` file.

## Verification

- Host builds:
  - No Sentry variables: the output is byte-identical to the build of the old configuration.
  - `SENTRY_PROJECT` without `SENTRY_ORG` or without `SENTRY_AUTH_TOKEN`: the build fails with the message of `vite.config.ts`.
  - All three, with `SENTRY_URL` at a closed port: the build fails at the upload, and `public/build` holds no `.map` file.
- The prepared upload of that build (`SENTRY_TEST_OVERRIDE_TEMP_DIR`): the chunk and its map have the same debug ID, the map has `sourcesContent`, and the frames `Nl` and `Il` of the report resolve to `assets/api/client.ts:47` (`apiGetAllPages`) and `:92` (`fetch`).
- `docker build --target assets` with the arguments and without the secret fails with the message of `vite.config.ts`.
- `make prod-check` passes. With `sourcemap: 'hidden'` always on, it fails.
- `make check` is green, except for the two audit steps of `make act`, which are red on `main` too ([F100](F100-audit-gates.md)).
- After the first deployment with the arguments, a new browser event shows the source frames. Jan checks it, because it needs the token.
