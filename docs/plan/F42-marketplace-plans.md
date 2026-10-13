# F42 Marketplace plans

Workflow, conventions, and status: [feature track](features.md). Jan selected on 2026-10-09 a GitHub Marketplace listing with free and paid plans, billed by the Marketplace only. [Requirements section 4](../requirements.md#4-non-goals-of-the-proof-of-concept) listed billing as "Not planned".

## Goal

- Slopmower knows the Marketplace plan of each GitHub account and gives each tenant the limits of its plan.
- The listing gets the webhook that every Marketplace listing must have.

## Facts

GitHub Docs, read on 2026-10-09:

- **Every listing:**
  - a public App, not by invite;
  - a pricing plan, a privacy policy link, a support link or email, and publisher contact;
  - logo, feature card, and screenshots;
  - "Apps must have webhook events set up to notify the publisher of any plan changes or cancellations";
  - integration "beyond authentication", which the clones and `push` already give.
  - For an App owned by an organization, only an organization owner submits the listing.
- **Paid plans:**
  - an organization that is a verified publisher, which needs 2FA, a verified domain, and a support email;
  - "GitHub Apps should have a minimum of 100 installations";
  - handling of the purchase events;
  - a monthly and a yearly USD price, flat rate or per unit, and a fixed trial of 14 days.
  - GitHub keeps 5%. It pays monthly from US$500.
  - "you can't list your app with a free pricing plan if you offer a paid service outside of GitHub Marketplace".
- **Purchase flow:**
  - GitHub installs the App and redirects to the Setup URL.
  - "Request user authorization during installation" must stay off.
  - The webhook actions are `purchased`, `changed`, `cancelled`, `pending_change`, and `pending_change_cancelled`.
  - The Marketplace webhook is set on the listing, not on the App. "GitHub doesn't resend failed delivery attempts".
  - On `cancelled`: "Remove all customer data within 30 days".

GitHub Docs, read on 2026-10-13:

- `GET /marketplace_listing/accounts/{account_id}`: "GitHub Apps must use a JWT to access this endpoint". It answers 404 "when the account has not purchased the listing". The answer has `plan.id`, `billing_cycle`, `on_free_trial`, `free_trial_ends_on`, `updated_at`, and `marketplace_pending_change` with `effective_date` and `plan`.
- `GET /marketplace_listing/stubbed/accounts/{account_id}` returns "hard-coded, fake data" with the same schema.

## Decisions

D65 in [architecture section 11](../architecture.md#11-decisions), and:

- **Webhook.** `marketplace_purchase` arrives at the same endpoint `/webhook/github` with the same secret. It goes into `HANDLED_EVENTS` and `GitHubWebhookConsumer::route()`, as `pull_request` in F40. The listing webhook has that URL, JSON, the secret, and Active.
- **Stored plan.** A `marketplace_account` record per GitHub account ID: plan ID, billing cycle, trial end, and the pending plan with its effective date. It belongs to no tenant, as `webhook_delivery`, so it goes into the exceptions of `EntityTenancyConventionTest`, and its job into `app.tenantless_messages`.
- **Actions.** `purchased` and `changed` store the plan and clear the pending change. `pending_change` stores the pending plan and its date. `pending_change_cancelled` clears it. `cancelled` deletes the record, which gives the free plan. GitHub does not uninstall the App.
- **Reconciliation.** At each sign-in, `GET /marketplace_listing/accounts/{id}` with the App JWT, for the user account and for each installation account. The answer replaces the record, and a 404 deletes it. So a lost delivery is repaired at the next sign-in. `GitHubInstallation` and `AccountPayload` get the account ID.
- **Plans.** A parameter maps each Marketplace plan ID to a plan key, a rank, and its F39 limit values, with environment variables. The free plan is also the plan of an account without a record. An unknown plan ID counts as the free plan and logs an error.
- **Plan of a tenant.** The plan with the highest rank among the user account and the installation accounts of the tenant. No record gives the free plan.
- **Data after a cancellation.** `cancelled` of a user account queues a job delayed by 30 days. The job reads the plan again. When GitHub answers 404 for the account and the tenant has no installation left, of any account, the job starts the tenant purge of M31. A new purchase or installation in the 30 days stops it. A failed plan read keeps the tenant, and the job retries.
- **Settings.** Specs first: `Me` gets `plan` with the plan name, the trial end, and the pending change. Settings shows the plan, the use and limits of F39, and a link to the listing, from the parameter `GITHUB_MARKETPLACE_URL`.
- **Setup URL after a purchase.** `/github/installed` (`GitHubInstalledController`) needs a signed-in user, so `LoginRedirectEntryPoint` sends a new buyer to the sign-in page first. After the sign-in, the saved target path opens `/github/installed`, which lists the installations again. F42 tests this path and keeps it.
- **No payment code.** Slopmower sells nothing outside the Marketplace (D65).
- **Review decisions, 2026-10-13.** The purge needs a 404 and no installation of any account. "No installation on that account" purged a user who cancelled the own plan and still works through an organization installation. The installation count runs under the tenant row lock of the purge. `APP_PAID_PLANS` refuses two paid plans with one rank, because the plan of a tenant would then depend on the row order.

## Depends on

F39: the limits that a plan sets.

## In scope

- Webhook route and job, the `marketplace_account` entity, the migration, and the account ID on `GitHubInstallation`.
- The reconciliation at sign-in, in the `LoginSuccessEvent` listener of the installation listing.
- Plan parameters, the plan resolution, and the F39 limits by plan.
- The delayed job after `cancelled`.
- Specs: `me.schema.json` `plan`, the fixtures, and [webhooks-and-live-updates.md section 1](../specs/webhooks-and-live-updates.md#1-github-webhooks), the `marketplace_purchase` row.
- UI: the plan in Settings.
- Configuration: `.env`, `.env.prod.dist`, and `docs/deployment.md`.
- Docs: [requirements section 4](../requirements.md#4-non-goals-of-the-proof-of-concept), D17, D65, and `docs/user/getting-started.md`.

## Out of scope

- Per-unit plans (candidate "Per-unit plans").
- Several users per tenant for organization plans (candidate).
- Any payment code.
- The listing content and the plans on GitHub ("For Jan" item "GitHub App").

## Acceptance tests

- **F** `MarketplaceWebhookTest`: `purchased` stores the plan of the account, `changed` replaces it, `pending_change` stores the pending plan, `pending_change_cancelled` clears it, and `cancelled` deletes the record. `DuplicateDeliveryTest` covers a duplicate delivery, because the consumer drops a recorded delivery ID before it routes the event.
- **F** `TenantPlanTest`: the highest rank among the user account and the installation accounts; no record gives the free plan; an unknown plan ID gives the free plan and logs an error; a paid plan raises an F39 limit.
- **F** `MarketplaceReconciliationTest`: at sign-in, a recorded answer replaces the record, and a 404 deletes it.
- **F** `CancelledAccountPurgeTest`: the delayed job purges a tenant whose account cancelled and has no installation left, and does nothing after a new purchase.
- **F** `EntityTenancyConventionTest`: `MarketplaceAccount` is an exception.
- **F** `GitHubInstalledRouteTest`: a signed-out request to `/github/installed` reaches the repositories after the sign-in.
- **UI** `SettingsPlan.spec.ts`: Settings shows the plan, the trial end, the pending change, and the listing link.

## Verification

- `make check` is green, with an empty `doctrine:migrations:diff`.
- Fixtures: answers of the stubbed endpoint until a draft listing exists, then responses and deliveries recorded from a purchase on the draft listing.
- With the draft listing: buy each plan, then use "Apply Pending Change" for a downgrade and a cancellation. Settings shows each change. The report records whether the GET answers 404 at once after `cancelled`.
