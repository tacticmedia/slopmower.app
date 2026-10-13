# M24 Live updates via Mercure

Workflow, conventions, and status: [README](README.md).

## Goal

Committed events reach open views through the hub that FrankenPHP has built in, isolated per tenant. Updates carry IDs only, and the browser revalidates its loaders. The contract is docs/specs/webhooks-and-live-updates.md section 3 and `docs/specs/schemas/live-update.schema.json`.

## Depends on

M22.

## In scope

- `composer require symfony/mercure-bundle`, version 0.5.0. With `extra.symfony.docker=false` the recipe adds no compose service. If a compose block appears, remove it.
- `config/packages/mercure.yaml`: an explicit `hubs.default.url` from `MERCURE_URL`, `public_url` from `MERCURE_PUBLIC_URL`, and the JWT secret from `MERCURE_JWT_SECRET`. The URL must always be set, decision D41.
- `LiveTopics`, with `{base}` equal to `DEFAULT_URI` without a trailing slash:
  - `{base}/tenants/{tenantId}/repositories`, matched exactly in the JWT;
  - `{base}/tenants/{tenantId}/reviews/{reviewId}`. The JWT holds this URI template, so it matches every review of the tenant.
- Payloads, IDs only:
  - `{type: "events", reviewId, seq, requestIds}` on the review topic;
  - `{type: "repository", repositoryId}` on the repositories topic.
  M26 adds `index`, M29 adds `outdated`, M31 adds `repository_deleted`, and M34 adds `candidates_changed`. There is no `resync` type.
- `PublishCommittedEvents`: a listener on `EventsCommitted` publishes one private update per committed transaction. The update ID is `urn:slopmower:{tenantId}:{seq}`, with the last event's `seq`. A publish failure is logged and not retried.
- `RepositoryChanged`: a Symfony event with the tenant ID and the repository ID, dispatched after the commit of every change to a repository: sync state, fetch, access, rename, and a deleted review. `PublishRepositoryChanges` listens to it and publishes `repository`. This milestone dispatches it in the use cases of M09 and M11. M31, M33, and M34 dispatch it where they say "publish `repository`".
- A3 `POST /api/v1/mercure/authorize`, session only:
  - sets the subscriber cookie: the hub's cookie name, `mercureAuthorization` by default, `HttpOnly`, `SameSite=Strict`, path `/.well-known/mercure`, and `Secure` when the request uses HTTPS;
  - the JWT claim `mercure.subscribe` holds the tenant's two selectors, and `exp` is now plus 3,600 seconds;
  - the response is `MercureAuthorization` with `hubUrl`, `topics`, and `expiresAt`.
  Read the bundle's `Authorization` class to build the cookie. The local environment serves HTTP, so the cookie there has no `Secure` flag.
- `useLiveRevalidation`, the one `useEventSource` hook of decision D29, as docs/specs/webhooks-and-live-updates.md section 3 says:
  - calls A3, then opens one `new EventSource(url, {withCredentials: true})`. `url` is `hubUrl` with one `topic` parameter for the repositories topic and one for the open review's topic, each encoded with `encodeURIComponent`;
  - calls A3 again before `expiresAt`;
  - keeps `lastSeq` per review from `events` updates, from `events[].seq` of command results, from `Review.lastEventSeq`, and from the `cursor` of the updates endpoint. An `events` update whose `seq` is not above `lastSeq` is ignored;
  - on an update, calls `revalidate()` of `useRevalidator`: for `events`, `outdated`, and `index` when `reviewId` is the open review, and always for `repository`, `repository_deleted`, and `candidates_changed`. Only one revalidation runs at a time. Updates that arrive during a revalidation cause one more revalidation after it. Unknown types are ignored;
  - after a reconnect, and when the tab becomes visible, calls `GET /api/v1/reviews/{reviewId}/updates?cursor=<lastSeq>&includeOwn=true` until `hasMore` is false, sets `lastSeq` to the last `cursor`, then revalidates once. The UI does not rely on `Last-Event-ID` replay.
- `RootLayout` mounts the hook once, so there is one connection. It subscribes to the repositories topic, and to the topic of the open review, which it takes from the route data.

## Out of scope

- Index progress: M26 hooks in.
- Outdated flags: M29 hooks in.

## Files (representative)

- `config/packages/mercure.yaml`.
- `src/Live/LiveTopics.php`, `PublishCommittedEvents.php`, `PublishRepositoryChanges.php`, `SubscriberAuthorization.php`.
- `src/Controller/Api/MercureAuthorizeController.php`.
- `assets/live/useLiveRevalidation.ts`, `assets/live/useLiveRevalidation.test.ts`.
- `tests/Unit/Live/*Test.php`, `tests/Functional/Live/*Test.php`, `tests/E2E/LiveUpdateTest.php`.

## Tasks (ordered)

1. Install the bundle and write the configuration.
2. Write the topics, the publishers, and A3.
3. Write the hook and use it on the routes.
4. Add the cross-tenant case for A3.
5. Write the tests.

## Acceptance tests

- **U `LiveTopicsTest`** proves topic isolation.
  - "Tenant A's selectors match none of tenant B's topics"
- **F `PublishAfterCommitTest`** proves post-commit publishing. It reads the traceable hub. Confirm its service ID with `make console c="debug:container mercure"`.
  - "One committed transaction publishes one private update with IDs only"
  - "The update validates against live-update.schema.json"
  - "A rollback publishes nothing"
- **F `RepositoryUpdateTest`** proves repository updates.
  - "A fetch publishes a repository update with the repository ID"
- **F `HubIsolationTest`** proves isolation at the real hub on port 8081.
  - "A's JWT receives A's update and not B's within 2 seconds"
- **F `MercureAuthorizeTest`** proves A3.
  - "Sets the subscriber cookie with HttpOnly, SameSite Strict, and the hub path"
  - "Adds Secure only when the request uses HTTPS"
  - "The JWT subscribe claim holds exactly the tenant's two selectors and expires in one hour"
  - "A bearer caller gets 403 forbidden"
- **V `UseLiveRevalidationTest`** proves the hook with a fake EventSource.
  - "Opens one EventSource with both topics"
  - "Updates during a running revalidation cause exactly one more revalidation"
  - "An events update with a seq not above lastSeq is ignored"
  - "An events update of another review causes no revalidation"
  - "Unknown update types are ignored"
  - "After a reconnect, the hook reads updates from lastSeq until hasMore is false"
- **E `LiveUpdateTest`** proves the flow in the browser.
  - "An agent reply over MCP appears within 5 seconds without a reload"

## Verification commands

```sh
make test
make front
make e2e
make check
```

By hand: open one review in two browser windows. A comment in one window shows in the other.

## Done

The UI does not poll.
