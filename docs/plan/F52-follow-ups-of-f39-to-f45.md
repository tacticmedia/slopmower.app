# F52 Follow-ups of F39 to F45

Workflow, conventions, and status: [feature track](features.md). The reviews and reports of F39 to F45 left four known issues in the Candidates list. The hard rule "bugs and issues before features" puts them ahead of new features.

## Goal

- The dev log stays small.
- The purge of failed messages says what it removed.
- An account deletion removes the Marketplace record of the personal GitHub account.
- The app and the docs link the privacy policy, the terms, and the support page.

## Facts

Verified in the code on 2026-10-15:

- `when@dev` in `config/packages/monolog.yaml` writes every channel except `event` at `debug` to `var/log/dev.log`. The file was 346 MB on 2026-10-14, mostly `doctrine` lines of the worker, which polls the queue every 2 s. The web profiler shows the queries of each request.
- `App\Messenger\FailedMessageSql::deleteCreatedBefore()` deletes with one `DELETE` and logs nothing. The default `PhpSerializer` writes the envelope into `body` and no headers. The failed deletion and tenant purge jobs are in the same queue. Messenger logs each failure at `critical` once, when it happens.
- `DeletionHandler::purge()` deletes the rows and files of a tenant. `marketplace_account` belongs to no tenant (F42), so the purge keeps it. `MarketplaceAccountSql` has `delete()`. Each sign-in reads the plan of the user account and of each installation account again and writes the record (F42 reconciliation).
- Only `templates/security/login.html.twig` and `docs/user/index.md` link the legal pages (F43). The app shell is the React app with the route `assets/routes/settings/SettingsRoute.tsx`. The docs pages use `templates/docs/layout.html.twig`.

## Decisions

- **Dev log.** The `dev` file handler leaves out the channel `doctrine`, because the profiler has the queries of each request, and becomes a `rotating_file` handler with `max_files: 2`. `test.log` stays a `stream`: `GitHubFetchSecretTest` and `BearerAuthenticationTest` read it by name.
- **Purge log.** The purge of the queue `failed` writes one record at `error` when it deletes at least one message: the count, and the ID and the message class of each deleted message. `error`, because a message that nobody retried in 30 days is a failure that nobody handled. The class comes from the stored envelope. The record holds no message content.
- **Marketplace record.** The tenant purge also deletes the `marketplace_account` record of the GitHub account of the tenant user, when no other tenant has that account as its user account or as an installation account. The next sign-in reads the plan again. The F43 privacy policy changes to say so.
- **Legal links.** Settings gets the links to the privacy policy, the terms, and the support page. The docs layout gets the same links in its footer.

## Depends on

Nothing. F49 and F50 (peer) do not touch these files, except `docs/user/` if F50 moves the deployment page.

## In scope

- `config/packages/monolog.yaml` (`when@dev`).
- `FailedMessageSql`, `RetentionHandler`, and `RetentionJobsTest`.
- `DeletionHandler` or `MarketplaceAccountSql`, and the purge tests.
- `SettingsRoute.tsx` or a component of it, `templates/docs/layout.html.twig`, and their tests.
- Docs: `docs/user/privacy.md` (the plan retention), `docs/developer-guide.md` where a change makes it wrong, and the four candidates in [features.md](features.md), which F52 closes.

## Out of scope

- The size of `test.log`.
- "Pull request state after a late permission" (a feature of its own).

## Acceptance tests

- **F** `RetentionJobsTest`: the purge of old failed messages logs one `error` record with the count, the IDs, and the message classes, and logs nothing when it deletes nothing.
- **F**: an account deletion deletes the Marketplace record of the personal account, and keeps it when another tenant has that account as an installation account.
- **UI**: Settings shows the three legal links.
- **F**: a docs page has the three legal links.

## Verification

- `make check` is green.
- `make up`, then after a few minutes of an idle worker, `var/log/dev.log` (or its dated file) has no `doctrine` lines.
- `docker compose exec -T php vendor/bin/mate tools:call monolog-tail` still reads the dev log, with its dated file name. AGENTS.md and the Mate instructions use it.
