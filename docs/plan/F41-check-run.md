# F41 Check run

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-09 for a deeper pull request integration. F41 shows the requests that a pull request covers as a check on the pull request, and a repository can make that check required.

## Goal

- A check run "Slopmower" on the head commit of each open linked pull request.
- The check stays in progress while a linked request is unfinished, so a required check blocks the merge until the user finishes the requests.

## Facts

GitHub Docs, read on 2026-10-09:

- Only a GitHub App can create a check run.
- At most 50 annotations per request. More go in update calls.
- Annotations take any path and line. They show in "Files changed" for the files of the pull request.
- A check run can be a required check with the App as its source.
- Apps with Checks: write receive `check_suite` and `check_run` events without a subscription.
- Rate limits: 5,000 requests per hour per installation, up to 12,500. At most 80 requests per minute and 500 per hour that create content. Send them in series.

GitHub Docs, read on 2026-10-13:

- An update "appends" its annotations "to the list of annotations that already exist for the check run". No call removes an annotation.
- "The `status` can be `queued`, `in_progress`, `requested`, `waiting`, `pending`, or `completed`." "Only GitHub Actions can set a status of `requested`, `waiting`, or `pending`."
- `annotation_level` is `notice`, `warning`, or `failure`.
- "If a check run is in an incomplete state for more than 14 days, then the check run's conclusion becomes stale".
- "Required status checks must have a `successful`, `skipped`, or `neutral` status". A ruleset that names an app as the source of a check needs the app "installed in the repository with the `statuses:write` permission". Branch protection lets you "select an app that has recently set this check".
- The REST API has no call that deletes a check run.

## Decisions

D64 in [architecture section 11](../architecture.md#11-decisions), and:

- **Permission.** The App registration gets Checks: write ([architecture section 9](../architecture.md#github-app-registration-for-hosted-runs)). The token of a check job asks for `checks: write` only.
- **One check per pull request.** Name "Slopmower", `head_sha` from the F40 record, `external_id` the ID of that record, and `details_url` the review of the newest link. The record keeps the check run ID and its head SHA.
- **Request states.** A request of a closed review counts as finished, because nobody can change it. A `wont_do` that the user set counts as finished, because it cannot become `verified`.

  | Request | Counts as | Annotation |
  |---|---|---|
  | `open`, `reopened`, `in_progress` | Unfinished | `warning` |
  | `ready_for_review`, `wont_do` set by an agent | Unfinished, waits for the user | `notice` |
  | `verified`, `wont_do` set by the user | Finished | None |
  | Any state, in a closed review | Finished | None |

- **Status and conclusion.**

  | Linked requests | `status` | `conclusion` |
  |---|---|---|
  | At least one unfinished | `in_progress` | None |
  | All finished | `completed` | `success` |
  | None, after the last unlink | `completed` | `neutral` |

- **Summary.** The linked requests per review, with "N of M finished" and a link to each request.
- **Annotations.** One per unfinished anchored request, located at the head SHA with `Locator::locate()` (`src/Anchoring/Locator.php`). The resolve-then-fetch pattern is `RequestQuery::target()` (`src/Application/Query/RequestQuery.php`). The first 50 go with the call that sets the output, the rest in update calls of 50. A general request, and a location that is `orphaned`, give no annotation, and the summary lists them.
- **New run when the annotations change.** Updates append annotations, so a job whose annotations differ from the last run creates a new check run on the same head SHA. A job with the same annotations updates the run.
- **Head not in the clone.** The job fetches once. A head SHA still missing after the fetch gives a check run without annotations, and the summary says why. The head of a fork pull request is on no branch of the repository, so it stays missing until the by-ID fetch of the "For Jan" item "GitHub App" exists.
- **Triggers:**
  - the events `pull_request.linked`, `pull_request.unlinked`, `request.ready_for_review`, `request.verified`, `request.reopened`, `request.wont_do`, and `review.closed`;
  - a new head SHA from F40, for example `pull_request` `synchronize`, which gives a new run on the new head;
  - `check_run` `rerequested` of a run with our `external_id`.
  - One job per pull request, debounced with a `DelayStamp`, as the fetch of `link_commits` is (`FetchRepository`).
  - A `symfony/lock` key per installation around the GitHub calls of a job, so the calls of one installation go in series.
  - `check_suite` events stay `github.ignored`.
- A job whose installation has not accepted Checks: write is skipped (D64). Local repositories get no check.

Review decisions, 2026-10-13. They replace the bullets above where they differ.

- **One check per tenant.** The name is "Slopmower (<login>)", with the GitHub login of the tenant's user. Runs of two tenants on one pull request never interfere, and a repository requires the check of the user whose review gates the merge. A choice between tenants would need one more suspension of the tenant filter.
- **Content.** An annotation holds the request headline, its state, and the link to the request, never the request text: a check run on a public repository is public, and GitHub cannot delete it.
- **Pull requests without a link.** With R8 "Check every pull request" on for the repository, off by default, a tenant posts a `completed` / `neutral` run "No linked requests" on `opened`, `reopened`, and `synchronize`, so a required check does not block them. Never when the tenant has a record of the number or an open record on the same head SHA, because a run belongs to a commit. Its `external_id` names the tenant.
- **No Statuses: write.** The user guide uses branch protection with the App as the source, and "any source" in a ruleset.
- **New run** when the status, the annotations, or the name change, or when the run is older than 14 days.
- **More triggers:** a record whose state changes to `open`, a review deletion, and `rerequested` of a run whose record is gone, which gets the neutral run.
- **Series:** a lock per record spans the whole job, and the lock per installation spans the GitHub calls inside it.

## Depends on

F40: the record, the head SHA, and the accepted permissions.

## In scope

- GitHub: the check job, its handler, and the `check_run` route in `GitHubRequestParser` and `GitHubWebhookConsumer`.
- Persistence: the check run ID and its head SHA on the F40 record, and the migration.
- Application: queue the job on the trigger events.
- Specs: [webhooks-and-live-updates.md section 1](../specs/webhooks-and-live-updates.md#1-github-webhooks), the `check_run` row.
- Docs: D64, architecture section 9, and a section in `docs/user/reviewing.md` that shows how to make "Slopmower" a required check with the App as its source.

## Out of scope

- Review comments, issues, or commit statuses (D64).
- `check_run` `requested_action` buttons.
- Fork heads, until the by-ID fetch exists.

## Acceptance tests

- **U** `CheckRunOutputTest`: the status, conclusion, and annotation level of each row of the two tables.
- **F** `CheckRunJobTest` with `MockHttpClient`:
  - a link creates a check run on the head SHA with the summary and the annotations;
  - 120 anchored requests give one call with 50 annotations and two update calls;
  - a verify that removes an annotation creates a new run, and a reply updates nothing;
  - an installation without Checks: write requests no token;
  - a head that is missing after one fetch gives a run without annotations.
- **F** `GitHubWebhookTest`: `check_run` `rerequested` with our `external_id` queues the job, and `check_suite` is `github.ignored`.
- **F**: two quick request events give one job.

## Verification

- `make check` is green, with an empty `doctrine:migrations:diff`.
- With a real App:
  - make "Slopmower" a required check, link a pull request with an unfinished request, and see the merge blocked;
  - verify the request, and the check passes;
  - push to the pull request, and a new run shows on the new head;
  - a required check takes the newest run of the name after a new run on the same head SHA. The report records it.
