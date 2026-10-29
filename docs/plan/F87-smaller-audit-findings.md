# F87 Smaller audit findings

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-10-29: "fix it all". These are the smaller findings of the repository audit of 2026-10-28, which the audit summary left out and the Candidate "Smaller audit findings" listed in part. Findings 7 to 20 are [F86](F86-audit-fixes-7-to-20.md).

## Goal

No GitHub delivery, job failure, or race leaves a wrong state for good. No input or commit date gives a 500. The front end shows errors where they belong, loads a large directory in one request, and catches up on its first live connection.

## Facts

From the six audit reports of 2026-10-28. Each is verified again in the code before its fix, and the report records any difference.

- **Check run flag.** `QueueCheckRun` sets `checkRunRequested`, and `CheckRunHandler` clears it only after it gets the lock. A `CheckRunJob` that fails before that, and goes to `failed`, leaves the flag set, so `requestCheckRun()` refuses every later trigger of the pull request.
- **Pull request events in one second.** `PullRequest` skips only payloads whose `updated_at` is older. GitHub's `updated_at` has one-second precision, so of a close and a reopen in one second, the one that commits last wins.
- **Arrival order.** A suspension job that retries after an unsuspension suspends the installation again. A redelivered Marketplace `purchased` after `changed` or `cancelled` sets an old plan.
- **Draft race.** Submit reads the drafts under the `drafts:<review>:<user>` lock, but PATCH and DELETE of a draft do not take it. An edit after the read is lost, and a deleted draft is still submitted. Create and update check `assertOpen()` on a review loaded before any lock.
- **Commit date.** `GitReader::commitInfo()` builds `committedAt` from `%ct` without a range check, and `TimeCodec::encode()` throws above the year 9999. `link_commits` of such a commit gives 500, and a pending link of it stops `ResolvePendingCommitLinks` for the repository.
- **Timezone.** `TIMESTAMP WITHOUT TIME ZONE` values are written as UTC wall time and read in `date_default_timezone_get()`. The image sets `date.timezone = UTC`, a manual install may not, and then token expiry shifts by the offset.
- **Long index job.** The worker runs `messenger:consume` without `--keepalive`, so an index job that runs longer than `redeliver_timeout` (3600 s, 900 s in dev) is delivered again and runs twice.
- **Large directories.** `listDirectory()` reads every page of V6, one after the other, and the tree and the table render every entry.
- **Revoke error.** The `revoke` intent of Settings awaits `apiWrite` without `writeResult`, so a 429 or 5xx replaces the page with the route error.
- **Viewed error.** `FileRoute` is not keyed by path, so the fetcher error of `ViewedCheckbox` stays after a file change.
- **Duplicates.** Three byte formatters with "MB" and "MiB" output, and `BlameColumn` repeats `linesText()`.
- **First live open.** `LiveSubscription` catches up only after an error, so an event between the loader read and the first open of the EventSource is lost.
- **Language of a path.** V14 and V16 join `indexed_file` to `symbol` by blob SHA only, so a byte-identical copy at a path of another language (`config.php.dist`) shows the symbols of the first.
- **Sync fetch without time.** A synchronous fetch that gets the lock just before the deadline fails with "timed out" and records a false failure. F86 D93 limits the lock wait to 5 s, which leaves at least 55 s to the fetch, so F87 has nothing to change.

## Decisions

- **D96 Check run request expiry.** `PullRequest.checkRunRequested` becomes `checkRunRequestedAt`. A request older than 10 minutes counts as gone, so a job that failed before it cleared the request, also one that cannot be decoded, blocks no later trigger.
- **Pull request tie.** A delivery whose `updated_at` equals the stored one and whose state differs queues `ReadPullRequestJob`, whose answer is the current state.
- **D97 GitHub reads, not delivery order.** A `marketplace_purchase` delivery reads the account from GitHub (`MarketplaceSync::syncAccount()`), whatever its action. An `installation` suspension or permission delivery reads `GET /app/installations/{id}` with the App JWT and records its `suspended_at` and `permissions`. The job keeps only the IDs.
- **Draft lock.** Update, delete, and submit of drafts take the `drafts:<review>:<user>` lock, and update and delete read the draft again under it. Create and update read the review again under the lock before `assertOpen()`, and close takes the lock of the closing user.
- **Commit date.** `GitReader::commitInfo()` clamps the committer time to the years 0000 to 9999 that `TimeCodec` encodes.
- **Timezone.** `Kernel::boot()` sets the PHP default timezone to UTC, so the `TIMESTAMP WITHOUT TIME ZONE` values read back as UTC on any host.
- **Long jobs.** The worker runs with `--keepalive`, so the Doctrine transport renews `delivered_at` of a running job, and `redeliver_timeout` no longer bounds a job.
- **Large directories.** V6 is read one page of 1,000 entries. The table and the tree say "Showing the first 1,000 entries." when more exist.
- **Settings revoke.** The `revoke` intent answers an `ActionResult`, and the token table shows it inline.
- **Viewed.** `ViewedCheckbox` is keyed by the path.
- **Formatters.** One byte formatter, in GitHub's style, for the file toolbar, the placeholder, and the usage table. `BlameColumn` uses `linesText()`.
- **First live open.** The first open of the EventSource for a review reads the events after `lastSeq`, and revalidates only when it found any.
- **Language of a path.** V16 drops a symbol row whose path language differs from the language of its parsed blob. V14 already takes PHP blobs only, and drops PHP symbols at a path that is not PHP.

## Depends on

[F86](F86-audit-fixes-7-to-20.md).

## In scope

Each finding above, its test, and its docs.

## Out of scope

- Virtualized rendering of a directory.
- A new GitHub App permission.

## Acceptance tests

One test per finding that fails without the fix, as listed in the report.

## Verification

`make cs`, `make lint`, `make stan`, `make test`, `make front`, `make ui`, and `make e2e`. A mutation check per fix with a test.
