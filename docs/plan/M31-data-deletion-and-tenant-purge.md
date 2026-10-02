# M31 Data deletion and tenant purge

Workflow, conventions, and status: [README](README.md).

## Goal

Users delete a review, a repository's data, or their account. Pin refs and files follow the rows. The contract is docs/specs/rest-api.md V13, R6, and A2.

## Depends on

M16, M19, M24, M25 for drafts and viewed files, M26 for the index, M29 for the location cache.

## In scope

- V13 `DELETE /api/v1/reviews/{reviewId}`, session only, 204 without a body:
  - it works for open and closed reviews, and for reviews of a repository whose access was removed;
  - `DeleteReview` deletes, in one transaction under the tenant row lock of M07, the review and all its rows: requests, comments, commit links, events, the location cache entries, the drafts of every user, and the viewed-file marks;
  - after the commit, when the clone exists, it deletes `refs/holistic-review/reviews/<reviewId>` and each `refs/holistic-review/commits/<sha>` that no remaining link in the repository uses;
  - the index stays, because it belongs to the repository and the commit;
  - it publishes a `repository` live update, because `openReviewCount` can change;
  - afterwards the IDs of the review, its requests, and its drafts answer 404.
- R6 `DELETE /api/v1/repositories/{repositoryId}?confirm={name}`, session only:
  - `confirm` must equal the repository `name`, else 422;
  - in the request, in one transaction under the tenant row lock: delete the repository row and all its rows: the reviews, the requests, the comments, the commit links, the events, the drafts, the viewed files, the location cache, the index, and the tokens scoped to it. So no query needs a "being deleted" filter. After the commit, publish `{type: "repository_deleted", repositoryId}` and queue `DeleteRepositoryFilesJob`;
  - the job holds the `RepositoryLock`, so it waits for a running fetch, and deletes the clone with its pin refs. A new connect of the same directory or GitHub repository gets a new repository ID, so it works before the job runs;
  - the response is 202 without a body. The GitHub App installation does not change.
- A2 `DELETE /api/v1/account?confirm={login}`, session only:
  - `confirm` must equal `user.login`, else 422;
  - in the request: revoke all tokens, invalidate the session so that the response clears the cookie, and mark the tenant as being deleted. The sign-in paths refuse a tenant that is being deleted, so no caller gets its tenant context again, and all its IDs answer 404 without a change to the queries;
  - in a Messenger job, as the normal database role: `PurgeTenant` deletes all rows and files of the tenant: every tenant table, the tenant directory with the clones and their pin refs, and the tokens. The `sessions` table has no tenant column. Other sessions of the user end at their next request, because `TenantUserProvider` refuses a user whose tenant is being deleted or gone;
  - the response is 202 without a body. The GitHub App stays installed until the user uninstalls it on GitHub.
- `app:tenant:purge <tenantId>`: an operator command that runs `PurgeTenant` inside `TenantFilter::suspend()`.
- UI:
  - "Delete review" in the review header, with a confirmation `Dialog`. Afterwards the user sees the repository page;
  - "Delete repository data" in Settings: the user types the repository name;
  - "Delete account" in Settings: the user types the login. Afterwards the user is signed out and sees `/login`.

## Out of scope

- Backups. Jan handles operations.
- Reopening a closed review: [docs/backlog/review-reopen.md](../backlog/review-reopen.md).

## Files (representative)

- `src/Application/DeleteReview.php`, `DeleteRepositoryData.php`, `DeleteAccount.php`, `PurgeTenant.php`.
- `src/Message/DeleteRepositoryFilesJob.php`, `src/Message/PurgeTenantJob.php`, `src/MessageHandler/*.php`.
- `src/Command/PurgeTenantCommand.php`.
- `src/Controller/Api/ReviewController.php`, `src/Controller/Api/RepositoryController.php`, `src/Controller/Api/AccountController.php`, `migrations/Version*.php`.
- `assets/review/DeleteReviewDialog.tsx`, `assets/settings/DeleteRepositoryDialog.tsx`, `assets/settings/DeleteAccountDialog.tsx`.
- `tests/Functional/Deletion/*Test.php`, `tests/E2E/DeleteReviewFlowTest.php`, `tests/E2E/DeleteRepositoryFlowTest.php`, `tests/E2E/DeleteAccountFlowTest.php`.

## Tasks (ordered)

1. Add the "being deleted" column to `Tenant` and run `make migration`. Make the sign-in lookups of M10 and M14, `TenantUserProvider`, and `GitHubUserProvider` of M32 refuse such a tenant.
2. Implement `DeleteReview`, `DeleteRepositoryData`, `DeleteAccount`, and `PurgeTenant`.
3. Write the endpoints, the jobs, and the command. Add the cross-tenant cases.
4. Build the dialogs.

## Acceptance tests

- **F `DeleteReviewTest`** proves V13 and that pins follow the rows.
  - "Deleting a review returns 204 and removes its rows, the drafts of every user, and its review pin"
  - "A commit pin shared with another review stays until its last link is gone"
  - "The index of the commit stays"
  - "A closed review and a review of a removed repository can be deleted"
  - "The IDs of the review and its requests answer 404 afterwards"
- **F `DeleteRepositoryDataTest`** proves R6.
  - "A confirm value that differs from the name gives 422"
  - "The response is 202, the repository and all its rows are gone, and repository_deleted is published"
  - "The same directory connects again before the job runs"
  - "The job deletes the clone"
  - "Other repositories of the tenant are untouched"
- **F `DeleteAccountTest`** proves A2.
  - "A confirm value that differs from the login gives 422"
  - "The response is 202 and clears the session cookie"
  - "All tokens are revoked and all IDs of the tenant answer 404"
  - "Another session of the same user is signed out at its next request"
- **F `PurgeTenantTest`** proves the purge.
  - "information_schema shows 0 rows of the tenant in every table with tenant_id"
  - "The tenant directory is gone"
  - "The other tenant is untouched"
- **F `CrossTenantDeleteTest`** proves isolation of deletions.
  - "A delete with another tenant's IDs gets 404 and deletes nothing"
- **E `DeleteReviewFlowTest`** proves the review dialog.
  - "Confirming the dialog deletes the review and shows the repository page without it"
- **E `DeleteRepositoryFlowTest`** proves the repository dialog.
  - "Typing the repository name and confirming removes the repository from the list"
- **E `DeleteAccountFlowTest`** proves the account dialog.
  - "Typing the login and confirming signs the user out"

## Verification commands

```sh
make migration
make db-test
make check
```

## Done

No row, file, or ref of a deleted review, repository, or account remains after its transaction or job.
