# Developer guide

This guide is a map of the Holistic Review code. It tells you what each part does, where it is, how a call moves through it, and which parts are larger than the job. The [architecture](architecture.md) and the [specifications](specs/README.md) stay the sources of truth. This guide points to them.

Size: `src/` has 399 PHP files and about 25,400 lines. One generated file, `src/Mcp/BundledSchemas.php`, has 2,472 of these lines. `assets/` has 128 TypeScript files and about 6,500 lines without tests. `tests/` has about 23,000 lines of PHP and 2,800 lines of Playwright UI tests.

## 1. Start here

### 1.1 The product

Holistic Review lets you review a full codebase at one commit, in a view like a GitHub pull request review, and each comment is a change request. Your coding agent reads the requests over MCP, does the work, and links its commits, and you verify or reopen each request in the browser.

### 1.2 Runtime pieces

| Piece | What runs there | Entry code |
|---|---|---|
| `php` container | FrankenPHP in worker mode. It serves the HTML shell, the REST API `/api/v1`, the MCP endpoint `/_mcp`, the user docs `/docs`, the GitHub webhook `/webhook/github`, and the built-in Mercure hub. It also serves the `e2e` site on port 8081 in classic mode (D34). | `src/Controller/`, `src/Mcp/`, `src/Webhook/` |
| `worker` container | `messenger:consume async`, same image and volumes. It clones and fetches, builds the symbol index, computes locations again, applies webhook jobs, and purges tenants. | `src/MessageHandler/`, `src/Message/` |
| `database` container | PostgreSQL 18. It holds all rows, the Messenger queue `doctrine://`, the sessions, and the advisory locks of `symfony/lock` (D39). | `src/Entity/`, `migrations/` |
| Host | Node.js 24 and Vite build `assets/` for the shell page. `npm run dev` gives hot reload. | `assets/`, `vite.config.ts` |
| Tenant data | One bare git clone per tenant and repository at `$APP_TENANT_DATA_DIR/<tenantId>/repositories/<repositoryId>.git`. | `src/Git/TenantDataPaths.php` |

Worker mode keeps services in memory between requests. Services stay stateless, and `TenantContext::reset()` clears the tenant after each request.

### 1.3 Three ideas to hold

1. **One event log, read through projections.** Each change to review data is an event row in `review_event`. `App\EventStore\CommandExecutor::execute()` is the only write path for these events. It locks the tenant row, calls a pure decider from `src/Domain`, appends the events with a gapless per-tenant `seq`, updates the projection tables in the same transaction, commits, and then dispatches `EventsCommitted`. Most reads use the projection tables `review`, `change_request`, `comment`, and `commit_link`. Thread and update reads also read `review_event`. See D3 and architecture section 4.
2. **A change request is anchored to code at one commit.** A review pins one commit. A request stores an `Anchor` that the server captured at that commit: path, blob, line range or symbol, and a snapshot of the lines. When the code changes, the server computes a `Location` at another commit. This is re-anchoring. A location is cached, never an event. The `outdated` flag of a request comes from the location at the default-branch head. See architecture section 5.
3. **Tenant isolation fails closed.** Code that reads tenant data runs with a tenant context. The context comes from the signed-in user, the API token, the tenant stamp of a Messenger message, or a console argument. A Doctrine SQL filter adds `tenant_id = :tenant` to each ORM query on a tenant table and throws when no context is set. Raw SQL exists only in `*Sql` classes, and each statement on a tenant table names its tenant column. Tests enforce both rules. See D3, D17 and architecture section 4.

One more fact helps: REST and MCP are thin adapters over one application layer, and they return the same JSON (architecture section 7.1).

### 1.4 Reading order

First read architecture sections 3 and 6. `src/Domain` implements them almost line for line. Then read these files in this order:

| # | File | Why |
|---|---|---|
| 1 | `src/Domain/Request/RequestState.php` | The folded state of one request and its invariants |
| 2 | `src/Domain/Request/AllowedActions.php` | Who may do what in which status. The decider and the API responses use the same function. |
| 3 | `src/Domain/Request/RequestDecider.php` | Command and state give events or a `DomainError`. The business rules. |
| 4 | `src/Domain/Request/RequestReducer.php`, `AwaitingPolicy.php` | How an event changes the state, and whose turn it is |
| 5 | `src/EventStore/CommandExecutor.php` | The only write path for events, 172 lines |
| 6 | `src/Projection/RequestProjector.php` | How events become `change_request`, `comment`, and `commit_link` rows |
| 7 | `src/Application/RequestCommands.php` | How each request command gets to the decider |
| 8 | `src/Mcp/Tool/ClaimRequestTool.php`, `src/Controller/Api/AgentRequestController.php` | One command through MCP and through REST |
| 9 | `src/Tenancy/TenantContext.php`, `TenantSqlFilter.php`, `TenantFilter.php` | Tenant isolation |
| 10 | `src/Application/FetchRepository.php`, `ServerClone.php` | The server clone. `FetchRepository` queues each clone and fetch, fetches within 60 s, and removes access. `ServerClone` fetches from the source. |
| 11 | `src/Anchoring/Locator.php`, method `compute()` | Re-anchoring. Read it next to architecture section 5.4. |
| 12 | `assets/router.tsx`, `assets/live/useLiveRevalidation.ts` | The front end: routes with loaders and actions, and the one live subscription |

Skip these on a first pass: `src/Mcp/BundledSchemas.php`, `assets/api/types/`, `src/Doctrine/Query`, `src/GitHub/Payload`, `src/Git/CatFileBatch.php`, and `src/Git/StreamedBlob.php`. The first file is generated, and the TypeScript types copy the JSON Schemas.

## 2. Domain map

### 2.1 Domain areas

**Repositories and the server clone.** A tenant connects a repository from a GitHub App installation or from a local directory under `APP_LOCAL_REPOSITORIES_DIR` (D24). The server keeps its own bare clone of each repository and reads code only from that clone. `SyncState` is the clone state: `cloning`, `idle`, `fetching`, `failed`, or `unavailable`. Pin refs under `refs/holistic-review/` keep reviewed and linked commits through force-pushes and `git gc` (D26). Code: `Application\FetchRepository`, `Application\ServerClone`, `MessageHandler\RepositorySyncHandler`, `App\Git`, `App\Source`, `App\GitHub`, `Repository\RepositoryRepository`.

**Reviews.** A review is one feedback round on one repository at one pinned commit. The start resolves the ref to a full SHA, and that SHA never changes. A review stays open until a user closes it. A closed review is read-only (D27). Two open reviews of one repository are fully separate. Code: `Domain\Review`, `Application\ReviewCommands`, `Entity\Review`.

**Change requests.** A request is one thread that asks for one change. Only a user opens a request. No MCP tool opens one, and `RequestDecider` refuses an `Open` from an agent. The six statuses are `open`, `in_progress`, `ready_for_review`, `wont_do`, `verified`, and `reopened`. An agent claims a request, replies, links commits, and marks it ready or won't do. The user verifies or reopens it. `awaiting` tells whose turn it is. The entity is `ChangeRequest`, and the table is `change_request`. Code: `Domain\Request`, `Application\RequestCommands`, `Entity\ChangeRequest`.

**Commit links.** An agent links a full commit SHA to the requests that it holds. A link is `pending` until the server clone has the commit, then `available`. After a fetch, `ResolvePendingCommitLinks` writes one `commit.found` event per found commit and review with the system actor. `ready_for_review` needs all links to be available (D19). Code: `Application\LinkCommits`, `Application\ResolvePendingCommitLinks`, `Entity\CommitLink`.

**Drafts and viewed files.** These are private state of one user in one review. They are not events. A draft is a pending new request or reply (D15). A user has at most 500 drafts per review. A submit turns all drafts into events of one batch in one transaction. A viewed file is the "Viewed" mark of a file. Code: `Entity\Draft`, `Entity\ViewedFile`, `Controller\Api\DraftController`, `RequestCommands::submitDrafts()`.

**Code reading and the symbol index.** The server reads the review commit from its clone: `git ls-tree` for trees and one `git cat-file --batch` process for content. At review start, a Messenger job writes the path list and the PHP symbols of the commit. Symbols give symbol anchors and the "Symbols" menu. The index belongs to a commit, so reviews of one commit share it. Code: `Git\GitReader`, `App\Index`, `MessageHandler\IndexReviewHandler`.

**Identity, tokens, and tenants.** A tenant is one developer account, with one user per tenant (D17). A browser user signs in with GitHub (D23), or with local sign-in in `dev` only (D25). An agent sends a per-user API token `hr_...` and acts as `agent`, with the token name as agent name (D13, D30). A token can be limited to one repository. Code: `App\Security`, `App\Tenancy`, `Entity\Tenant`, `Entity\User`, `Entity\ApiToken`.

**Live updates.** After a commit, the server publishes a private Mercure update that holds IDs only (D42). The browser keeps one `EventSource` and revalidates its React Router loaders. The agent gets no push. It polls `get_updates` (D21). Code: `App\Live`, `assets/live/`.

**Deletion.** A user deletes a review, the data of one repository, or the account. Review and repository rows go in the HTTP request under the tenant row lock. Clones and account data go in Messenger jobs. When GitHub removes access, the server deletes the clone and the index and keeps the reviews (D22). Code: `Repository\DeletionSql`, `MessageHandler\DeletionHandler`, `FetchRepository::removeAccess()`.

### 2.2 Request lifecycle at a glance

| Command | From | To | Who |
|---|---|---|---|
| `open` | none | `open` | User |
| `claim` | `open`, `reopened` | `in_progress` | Agent |
| `release` | `in_progress` | The status before the claim | Holder, or user with "Unassign" |
| `link_commits`, `unlink_commit` | `in_progress` | Unchanged | Holder |
| `mark_ready_for_review` | `in_progress` | `ready_for_review` | Holder. All links must be available. |
| `mark_wont_do` | `open`, `reopened`, `in_progress` | `wont_do` | Agent, the holder when `in_progress`, or user. The reason has 20 or more characters. |
| `verify` | `ready_for_review`, or `wont_do` set by an agent | `verified` | User |
| `reopen` | `ready_for_review`, `wont_do`, `verified` | `reopened` | User |
| `reply` | Any | Unchanged | User or agent. An agent can set `question`. |

A closed review allows no request command. Architecture section 6 has the full table and the `awaiting` rules, and `AllowedActions::permits()` is the same table in code. The 14 event types are `review.started`, `review.closed`, `request.opened`, `request.commented`, `request.claimed`, `request.released`, `request.ready_for_review`, `request.wont_do`, `request.verified`, `request.reopened`, `commit.linked`, `commit.unlinked`, `commit.found`, and `batch.submitted`.

### 2.3 What "anchoring" means

The word covers two jobs:

| Job | When | Input | Output | Code |
|---|---|---|---|---|
| Capture | A user opens a request or saves a draft | Kind, path, lines or symbol FQN | `Anchor`, with a `ContentSnapshot` except for a file anchor, stored in `draft.anchor`, or in `request.opened` and `change_request.anchor` | `Application\AnchorFactory::capture()`, `Domain\Anchor\SnapshotCapture::capture()` |
| Re-anchoring | A caller needs the anchor at another commit: the default-branch head, an `at` commit, or a linked commit | `Anchor` and a target SHA | `Location` with status `exact`, `shifted`, `changed`, or `orphaned` | `App\Anchoring\Locator::locate()` |

The code sits in four namespaces:

```text
App\Domain\Anchor      Anchor value object, snapshot capture, comparison form, normalized hash
App\Domain\Matching    pure algorithms on line arrays: diff parser, range mapper, matcher, similarity, best window
App\Domain\Location    the result: Location, LocationStatus, OrphanReason, LocationTarget
App\Anchoring          git and cache side: Locator, LocationCacheSql, QueueLocationRecompute, CodeExcerpt
```

`Locator::compute()` calls the `Domain\Matching` functions in the order of architecture section 5.4, so it explains that namespace best. The order is: map the path through renames, compare blob SHAs, find a symbol by FQN, map the range through `--unified=0` hunks, find an exact normalized match, compute the similarity of the mapped range or search the best fuzzy window, search other files. The result is cached in `location_cache_entry` by the `request.opened` event ID and the target SHA. The cache has no eviction, because both commits never change. `RecomputeLocationsHandler` is the only writer of `outdated`, and a projection rebuild keeps it.

### 2.4 Glossary

| Term | Meaning | Where |
|---|---|---|
| Tenant | One developer account and all its data | `Entity\Tenant` |
| Tenant context | The current tenant of a request, message, or command. A query without it throws `MissingTenantContext`. | `Tenancy\TenantContext` |
| Server clone | The bare clone that the server keeps per repository. Code reads use only this clone. | `Git\TenantDataPaths::clonePath()` |
| Sync state | Clone state: `cloning`, `idle`, `fetching`, `failed`, `unavailable` | `Domain\Repository\SyncState`, `RepositoryRepository` |
| Pin ref | `refs/holistic-review/reviews/<reviewId>` and `refs/holistic-review/commits/<sha>`. Fetch refspecs never touch them (D26). | `Git\PinRefs` |
| Review commit | The one commit of a review. All code reads and anchor captures use it. | `Entity\Review::getCommitSha()` |
| Request | One change request thread. The table is `change_request`. | `Domain\Request\RequestState`, `Entity\ChangeRequest` |
| Claim, holder | An agent claims a request and holds it while `in_progress`. A repeated claim by the holder writes no event. | `RequestDecider`, `RequestState::isClaimedBy()` |
| Awaiting | Whose turn it is: `agent`, `user`, or `none`. Rules 1 to 5 of architecture section 6, first match wins. | `Domain\Request\AwaitingPolicy::evaluate()` |
| Allowed actions | The actions the caller may take now. Empty on a closed review. | `Domain\Request\AllowedActions::for()` |
| Resolution | `fixed` after `ready_for_review`, `wont_do` after `wont_do`. `reopen` clears it. | `Domain\Request\Resolution` |
| Commit link | A commit linked to a request, `pending` or `available` | `Entity\CommitLink`, `Domain\Request\LinkedCommit` |
| `commit.found` | System event that makes pending links available after a fetch. No Domain decider writes it. | `Application\ResolvePendingCommitLinks::recordFound()` |
| Availability | The `CommitInfo` list of commits in the clone that the application gives to the decider | `RequestDecider::decide()` parameter `$availability` |
| Actor | `user`, `agent`, or `system`. Two tokens of one user with the same name are the same agent. | `Domain\Actor\Actor`, `Security\CurrentActor::get()` |
| Event | One `review_event` row. 14 types. Stored events are never rewritten. | `Domain\Event\EventType` |
| `seq` | Gapless event number per tenant, from `tenant.event_seq` under `FOR UPDATE`. The `get_updates` cursor and the Mercure update ID. | `EventStore\EventStoreSql::lockTenant()` |
| Decided or recorded event | `DecidedEvent` is a decider result. `RecordedEvent` is the stored event with ID, `seq`, actor, and time. | `Domain\Event\DecidedEvent`, `RecordedEvent` |
| Decider | Pure static function: command and state give events, or a `DomainError` | `RequestDecider::decide()`, `ReviewDecider::decide()` |
| Reducer | Pure static function: state and event give the next state | `RequestReducer::apply()`, `ReviewReducer::apply()` |
| Projection | A read table that changes in the transaction of its events. `app:projections:rebuild` rebuilds it. | `Projection\RequestProjector`, `ReviewProjector` |
| Not event data | Data that a rebuild keeps: `outdated`, the index columns of `review`, drafts, viewed files | Docblocks of `Entity\Review`, `ChangeRequest`, `Draft` |
| Fresh read | A query with `Query::HINT_REFRESH`, so a stale identity-map entity does not win | `*Repository::findFresh()` |
| Anchor | Where a request points at the review commit | `Domain\Anchor\Anchor` |
| Anchor kind | `line`, `range`, `symbol`, or `file`. A symbol range includes the doc comment (D7). A file anchor has no range and no snapshot. | `Domain\Anchor\AnchorKind` |
| Content snapshot | The anchored lines, all up to 200, else the first 100 and the last 100. Also up to 3 context lines on each side and `normalizedHash`. | `Domain\Anchor\ContentSnapshot` |
| Comparison form | A line trimmed, with inner whitespace runs collapsed to one space. All content comparisons use it. | `SnapshotCapture::comparisonForm()` |
| Distinctive snapshot | 8 or more non-whitespace characters. Only these get a content search. | `Domain\Matching\SnapshotMatcher::isDistinctive()` |
| Hunk, mapped range | A `--unified=0` diff block, and the anchor range moved through the hunks. `untouched` means no hunk changes the region. | `Domain\Matching\RangeMapper::mapRange()` |
| Location | Where an anchor is at a target commit: status, path, range, confidence, orphan reason | `Domain\Location\Location` |
| Location status | `exact`, `shifted`, `changed`, `orphaned` | `Domain\Location\LocationStatus` |
| Orphan reason | `file_deleted`, `region_deleted`, `ambiguous`, `content_not_found` | `Domain\Location\OrphanReason` |
| Location target | `default_branch`, `requested` for the `at` commit, `linked_commit` | `Domain\Location\LocationTarget` |
| Outdated | True when the location at the default-branch head is `changed` or `orphaned`. Null without a clone or a default branch. | `ChangeRequest::recordOutdated()` |
| Draft, batch | Pending new requests and replies of one user, submitted together with `batch.submitted` | `Entity\Draft`, `RequestCommands::submitDrafts()` |
| Renderable file | A regular file of at most 1 MiB with no NUL byte in the first 8000 bytes | `Git\TreeEntry::isRenderableFile()`, `Git\Blob::isBinary()` |
| Snapshot, three meanings | `ContentSnapshot` holds code lines. `ReviewSnapshot` is folded review state. `ReadSnapshotSql` runs a REPEATABLE READ transaction. | `Domain\Anchor`, `Domain\Review`, `EventStore` |
| Live update | Private Mercure message with IDs only: `events`, `outdated`, `index`, `repository`, `repository_deleted`, `candidates_changed` | `Live\LivePublisher`, `assets/api/types/live-update.ts` |
| `*Sql` class | The only kind of class that may run raw SQL | `RawSqlTenantAuditTest` |
| Spec IDs | Endpoint IDs in comments: A account, R repositories, V reviews and code, Q requests, D drafts, T tokens. Endpoint IDs D1 to D5 collide with decisions D1 to D44. | `specs/rest-api.md` section 2, architecture section 11 |

## 3. Namespace map

### 3.1 PHP namespaces

| Namespace | Files | Purpose | Key types | Main dependencies |
|---|---|---|---|---|
| `App` | 1 | Kernel. Allows the `e2e` environment. Makes the HWIOAuth GitHub resource owner non-shared. | `Kernel` | FrameworkBundle |
| `App\Domain\Request` | 12 | Request state machine: decide, fold, awaiting, allowed actions | `RequestDecider`, `RequestReducer`, `RequestState`, `AllowedActions`, `AwaitingPolicy` | Domain only |
| `App\Domain\Request\Command` | 11 | Input DTOs of the 10 request commands | `RequestCommand`, `Open`, `Claim`, `Reply`, `LinkCommits` | Domain only |
| `App\Domain\Review` | 7 | Review start and close, with the same decider and reducer shape | `ReviewDecider`, `ReviewReducer`, `ReviewSnapshot` | Domain only |
| `App\Domain\Event` | 18 | The 14 event types and the two envelopes | `DomainEvent`, `EventType`, `DecidedEvent`, `RecordedEvent` | Domain only |
| `App\Domain\Event\Codec` | 5 | Stored JSON format of payloads (D37). The shared normalizers also use `AnchorCodec` and `TimeCodec`. | `PayloadReader`, `AnchorCodec`, `PayloadJson`, `TimeCodec` | Domain only |
| `App\Domain\Anchor` | 7 | Anchor value object and capture rules | `Anchor`, `ContentSnapshot`, `SnapshotCapture`, `LineRange` | Domain only |
| `App\Domain\Matching` | 9 | Pure re-anchoring algorithms on line arrays | `UnifiedZeroDiffParser`, `RangeMapper`, `SnapshotMatcher`, `BestWindow` | Domain only |
| `App\Domain\Location` | 4 | Result types of re-anchoring | `Location`, `LocationStatus`, `OrphanReason` | Domain only |
| `App\Domain\Error` | 10 | Exceptions that map to the API error codes | `DomainError`, `ErrorCode`, `InvalidInput`, `InvalidTransition` | Domain only |
| `App\Domain\Actor`, `Identity`, `Git`, `Repository`, `ApiToken` | 27 | Value types: actor, ULID IDs, SHAs, paths, refs, repository names, token secret, small enums | `Actor`, `Identifier`, `CommitSha`, `RepoPath`, `RefName`, `ApiTokenSecret` | `Symfony\Component\Uid` in `Identity` |
| `App\Application` | 12 | Use cases that REST, MCP, webhooks, and jobs share. Git and Messenger side effects outside the event transaction. | `RequestCommands`, `ReviewCommands`, `FetchRepository`, `ServerClone`, `AnchorFactory`, `LinkCommits`, `MarkReadyForReview` | Domain, EventStore, Projection, Entity, Repository, Git, Source, GitHub, Index |
| `App\Application\Query` | 32 | Read side: 6 query services and 26 response DTOs and filter types | `RequestQuery`, `UpdatesQuery`, `ThreadQuery`, `ReviewQuery`, `FileQuery` | Domain, Entity, Repository, Anchoring, Git, `Http\Cursor` |
| `App\Application\Event` | 2 | Events after a repository fetch or change | `RepositoryFetched`, `RepositoryChanged` | Domain |
| `App\EventStore` | 4 | Append-only log and the single event write path | `CommandExecutor`, `EventStoreSql`, `EventsCommitted`, `ReadSnapshotSql` | Domain, Projection, Entity, Tenancy |
| `App\Projection` | 3 | Keeps the read tables in step with events, in the write transaction | `RequestProjector`, `ReviewProjector`, `RequestStateMapper` | Domain, Entity, Repository |
| `App\Entity` | 20 | Doctrine entities, one table each. 16 implement `TenantOwned`. The enums `DraftKind` and `SignInMethod`. | `Tenant`, `Repository`, `ReviewEvent`, `Review`, `ChangeRequest`, `CommitLink`, `ApiToken` | Domain, Doctrine, Tenancy, Security |
| `App\Repository` | 18 | 16 ORM repositories plus `DeletionSql` and `ViewedFileSql`: fresh reads, conditional state updates, deletes | `RepositoryRepository`, `ReviewEventRepository`, `DeletionSql` | Entity, Domain |
| `App\Doctrine` | 9 | DBAL types for value objects, jsonb DQL functions, `ActorEmbeddable` | `AnchorType`, `CommitShaType`, `JsonbContains` | Domain |
| `App\Tenancy` | 7 | Fail-closed tenant context and the two SQL filters | `TenantContext`, `TenantSqlFilter`, `TenantFilter`, `RepositoryScopeSqlFilter` | Entity |
| `App\Security` | 15 | Session sign-in, bearer tokens, principal to `Actor` | `ApiTokenHandler`, `AgentUser`, `CurrentActor`, `TenantUserProvider` | Entity, Repository, Tenancy |
| `App\Git` | 15 | The only code that runs git. Server clone reads and writes. | `GitRunner`, `GitReader`, `BareClone`, `PinRefs`, `RepositoryLock` | Domain |
| `App\Source` | 5 | Local discovery and the fetch command of each source | `LocalRepositoryDiscovery`, `FetchCommand` | Domain |
| `App\GitHub` | 9 | GitHub App: JWT, installation tokens, the listing at sign-in | `InstallationSync`, `InstallationTokenProvider`, `GitHubApp` | Application, Entity, Repository |
| `App\Webhook` | 3 | GitHub webhooks: parse, verify, fan out one job per tenant | `GitHubRequestParser`, `GitHubWebhookConsumer`, `WebhookDeliverySql` | GitHub, Message, Messenger, Repository |
| `App\Index` | 7 | PHP symbol extraction and storage per blob | `BlobSymbols`, `PhpSymbolExtractor`, `IndexSql` | Git, Entity |
| `App\Anchoring` | 4 | Git and cache side of re-anchoring | `Locator`, `LocationCacheSql`, `QueueLocationRecompute` | Domain, Git, Index, Repository |
| `App\Message` | 13 | Messenger messages, all on the `async` transport. The GitHub jobs also carry webhook payload fields. | `FetchRepositoryJob`, `IndexReview`, `RecomputeLocations` | Domain |
| `App\MessageHandler` | 7 | Every Messenger handler: clone and fetch, deletion, index, locations, GitHub webhook jobs | `RepositorySyncHandler`, `DeletionHandler`, `IndexReviewHandler`, `RecomputeLocationsHandler`, `GitHubInstallationHandler` | Application, Git, GitHub, Anchoring, Index, Live |
| `App\Messenger` | 2 | Carries the tenant across the Messenger boundary | `TenantContextMiddleware`, `TenantStamp` | Tenancy |
| `App\Live` | 4 | Mercure publish, IDs only | `LivePublisher`, `LiveTopics`, `PublishCommittedEvents` | EventStore, Application\Event |
| `App\Controller` | 6 | Shell routes, sign-in pages, `/docs`, `/healthz`, `/github/installed`, e2e sign-in | `ShellController`, `DocsController`, `SecurityController` | Docs, Security |
| `App\Controller\Api` | 19 | REST adapter: one action per spec endpoint | `RequestController`, `RequestCommandController`, `AgentRequestController`, `ReviewController` | Application, Query, Security |
| `App\Controller\Api\Input` | 27 | Request DTOs for `#[MapRequestPayload]` and `#[MapQueryString]` | `AnchorInput`, `RequestListQuery`, `LinkCommitsInput` | Domain, Query |
| `App\Http` | 6 | The `/api/v1` pipeline: error body, CSRF, body size, headers, cursor | `ApiExceptionListener`, `ApiCsrfListener`, `Cursor` | Domain |
| `App\Serializer\Normalizer` | 17 | JSON per schema, shared by REST and MCP (D37) | `RequestNormalizer`, `RequestSummaryNormalizer`, `EventNormalizer` | Query, Domain |
| `App\Mcp` | 4 | MCP runner, two session decorators, generated output schemas | `McpToolRunner`, `SessionLockedMcpController`, `TokenBoundSessionStore` | Serializer, Security |
| `App\Mcp\Tool` | 12 | The 12 MCP tools of `specs/mcp.md` section 4 | `GetUpdatesTool`, `GetRequestTool`, `ClaimRequestTool` | Application, Query |
| `App\Docs` | 2 | Serves `docs/user` and the schemas through a lookup in a directory listing (D28) | `DocsCatalog`, `DocsLinkRewriter` | Finder, CommonMark |
| `App\Command` | 4 | `app:projections:rebuild`, `app:index:rebuild`, `app:tenant:purge`, `app:schema:bundle` | `RebuildProjectionsCommand`, `BundleSchemasCommand` | EventStore, MessageHandler, Index |

### 3.2 Front end

| Folder | Files | Purpose |
|---|---|---|
| `assets/app.tsx`, `router.tsx`, `layout/` | 7 | Entry, the one data router, `RootLayout` with the live hook (D29) |
| `assets/api/` | 29 | `fetch` client with the CSRF header, `ApiError`, TypeScript types written from the schemas |
| `assets/routes/` | 27 | Screens. Loaders read and actions write. Most actions branch on an `intent` field. |
| `assets/code/` | 8 | `CodeFile` and `CommitDiff`, the only wrappers around the pinned `@pierre/diffs` (D1), and their shared `workerPool` options |
| `assets/comments/` | 11 | Threads, drafts, comment box, `useOptimistic` updates |
| `assets/live/` | 2 | `LiveSubscription`, the one Mercure subscription, and `EventPositions` |
| `assets/requests/`, `review/`, `settings/` | 44 | Presentational components and pure helpers |

### 3.3 Dependency picture

```text
Adapters      Controller\Api   Mcp\Tool   Webhook           Command   MessageHandler
                    |              |             |              |            |
                    +--------------+------+------+--------------+------------+
                                          |
Application         Application (commands)        Application\Query (reads)
                          |                                 |
Write path          EventStore\CommandExecutor --> Projection --> Entity, Repository
                          |
Domain              Domain\*   pure: imports only App\Domain, Symfony\Component\Uid, PHP core

Ports:          Git, Source, GitHub, Index, Anchoring, Live
Cross-cutting:  Tenancy, Security, Http, Messenger, Serializer\Normalizer
```

- `src/Domain` imports only `App\Domain`, `Symfony\Component\Uid`, and PHP core. `tests/Unit/Conventions/DomainPurityTest.php` enforces it. It has no services and no I/O, and the deciders, reducers, and algorithms are static functions.
- Event data goes only through `CommandExecutor`. Data that is not event data has other writers, for example `IndexReviewHandler`, `RecomputeLocationsHandler`, the conditional updates of `RepositoryRepository`, drafts, viewed files, and tokens.
- No test enforces namespace dependencies outside `src/Domain`. These 13 namespace cycles exist in code: `Entity` with `Repository`, `Tenancy`, `Security`, `GitHub`, and `Index`; `Repository` and `Security`; `Application` with `GitHub` and `Anchoring`; `Controller` and `Http`; `Domain\Error` with `Domain\Request`, `Domain\Identity`, and `Domain\Actor`; and `Domain\Request` and `Domain\Event`. `Mcp\Tool\ListRequestsTool` imports `Controller\Api\Input\RequestListQuery`.

### 3.4 Where a change goes

Change the specs first when a contract changes. The plan forbids a wire format that `docs/specs/` does not define.

| Change | Files to touch |
|---|---|
| A new field on a request | `RequestState`, `RequestReducer`, `ChangeRequest`, `RequestStateMapper`, a migration, `RequestSummaryView` or `RequestView`, its normalizer, the schema, the TypeScript type |
| A new request command | A DTO in `Domain\Request\Command`, an `Action` case, `AllowedActions`, `RequestDecider`, an event class with `toPayload()` and `fromPayload()`, an `EventType` case, `RequestReducer`, `RequestCommands`, one REST action, one MCP tool |
| A new REST endpoint | An action in `Controller\Api`, an input DTO in `Controller\Api\Input`, a service in `Application` or `Application\Query` when another adapter shares the logic, a normalizer, a cross-tenant case. `CrossTenantCoverageTest` fails without the case. |
| A new MCP tool | A class in `Mcp\Tool` that calls `McpToolRunner::run()`, a cross-tenant case, and for a new output schema a run of `app:schema:bundle` |
| A new tenant table | An entity that implements `TenantOwned`, a rule in `RepositoryScopeSqlFilter`, the delete lists in `DeletionSql`, a migration. `EntityTenancyConventionTest` checks the tenant column. |
| A new background job | A message in `App\Message` with `#[AsMessage('async')]` and a handler in `App\MessageHandler`. Run `make worker-restart` in dev, because the Messenger worker does not reload code. |

## 4. Traced flows

### 4.1 A user starts a review

1. `repositoryAction()` in `assets/routes/repositories/RepositoryRoute.tsx` gets intent `start` and calls `apiWrite('POST', '/api/v1/repositories/{id}/reviews')`. This is endpoint V1.
2. The `api` firewall restores the session. `TenantUserProvider::loadUserByIdentifier()` finds the user inside `TenantFilter::suspend()` and calls `TenantContext::set()`. `TenantContextListener::__invoke()` sets the same tenant at priority 7.
3. `#[MapEntity]` loads the `Repository` through the tenant filter, so an ID of another tenant gives 404. `ApiCsrfListener` checks the double-submit token. `#[IsGranted(User::ROLE)]` refuses an agent.
4. `ReviewController::start()` calls `RefName::fromString()`, then `ReviewCommands::start()` with `CurrentActor::get()`, which gives `Actor::user()`.
5. `FetchRepository::readyClonePath()` throws `RepositoryUnavailable` for a clone in `cloning`, `failed`, or `unavailable`.
6. When the ref is not a full SHA in the clone, `FetchRepository::fetchNow()` waits for the `fetch:<repositoryId>` lock in `RepositoryLock::acquire()` and calls `ServerClone::fetch()`, both within 60 s. This fetch also dispatches `RepositoryFetched`, so steps 6 to 11 of flow 4.3 follow.
7. A null ref becomes the default branch. `GitReader::resolveCommit()` gives the `CommitSha`. `PinRefs::pinReview()` writes the review pin before the transaction (D26).
8. `ReviewCommands::recordStarted()` calls `CommandExecutor::execute()`. `EventStoreSql::lockTenant()` runs `SELECT event_seq FROM tenant ... FOR UPDATE`. The decide closure reads `RepositoryRepository::findFresh()`, refuses removed access, and calls `ReviewDecider::decide()`, which returns `review.started`.
9. `EventStoreSql::append()` inserts the event. The `inTransaction` closure persists the new `Review`, because the repository is not event data. `ReviewProjector::project()` folds `ReviewReducer::apply()` into the row. The transaction commits.
10. `CommandExecutor` dispatches `EventsCommitted`. `PublishCommittedEvents::__invoke()` publishes an `events` update. If step 8 or 9 fails, `PinRefs::unpinReview()` removes the pin.
11. `ReviewCommands::start()` dispatches `IndexReview`. `TenantContextMiddleware::handle()` adds a `TenantStamp`. The controller answers `ReviewQuery::get()` with 201.
12. In the worker, `TenantContextMiddleware::handle()` sets the tenant from the stamp. `IndexReviewHandler::__invoke()` calls `GitReader::lsTreeRecursive()`, `IndexSql::insertFiles()`, and `BlobSymbols::parse()` per new PHP blob, and publishes `index` updates at most once per second.
13. The browser action redirects to `/reviews/{id}`. `reviewLoader()` reads V3, D1, and V11. `LiveSubscription.watch()` subscribes to the review topic. Each `index` update revalidates the loaders.

### 4.2 An agent replies over MCP, and the browser updates live

An agent cannot open a request. A user opens one through `RequestController::open()`, `AnchorFactory::capture()`, and `RequestCommands::open()`. The agent side is the reply:

1. The agent sends `tools/call reply_to_request` to `POST /_mcp` with `Authorization: Bearer hr_...`.
2. The `mcp` firewall calls `ApiTokenHandler::getUserBadgeFrom()`. It finds the token by its SHA-256 inside `TenantFilter::suspend()`, requires `ApiTokenState::Active` and a live owner, calls `TenantContext::set()`, and calls `restrictToRepository()` for a scoped token. It returns an `AgentUser`. `access_control` requires `ROLE_AGENT`.
3. `SessionLockedMcpController::handle()` holds the lock `mcp-session-<id>`, so one request per MCP session runs at a time. `TokenBoundSessionStore` binds the session to the token.
4. The SDK checks the arguments against `#[Schema]` and calls `ReplyToRequestTool::replyToRequest()`. `McpToolRunner::run()` runs the closure. `CurrentActor::get()` gives `Actor::agent(userId, agentName)`.
5. `RequestCommands::reply()` calls `reviewOf()`. The SQL filters apply to this lookup, so a request of another tenant or outside the token scope gives `NotFound`. Then `run()` calls `CommandExecutor::execute()`.
6. Under the tenant row lock, the decide closure reads `ReviewRepository::findFresh()`, builds the state with `RequestStateMapper::toState()` from `ChangeRequestRepository::findFresh()` and `CommitLinkRepository::findFreshOf()`, and calls `RequestDecider::decide()`. The checks run in the order `invalid_input`, `review_closed`, `invalid_transition`, `commits_not_available`. The result is `request.commented`.
7. `CommandExecutor` numbers the event with the next `seq` and calls `EventStoreSql::append()`. `RequestProjector::project()` folds `RequestReducer::apply()`, writes the state with `RequestStateMapper::update()`, including `awaiting` from `AwaitingPolicy::evaluate()`, and persists a `Comment`. `ReviewProjector::project()` moves `Review.lastEventSeq`. The transaction commits.
8. `EventsCommitted` goes to `PublishCommittedEvents::__invoke()`, which publishes `{type: events, reviewId, seq, requestIds}` on the review topic with the ID `urn:holistic-review:<tenantId>:<seq>`. `QueueLocationRecompute::onEventsCommitted()` does nothing, because no `request.opened` is in the batch.
9. The tool returns `RequestQuery::commandResult()`. The shared normalizers give the same JSON as REST Q4. A `DomainError` becomes an `isError` result with the error body.
10. In the browser, `LiveSubscription.#receive()` gets the `events` update for the open review with a new `seq`. `#requestRevalidation()` and `#revalidate()` wait for `routerIdle()`. They skip a `seq` that `EventPositions` already covers, which is the echo of an own write.
11. `router.revalidate()` runs `requestLoader()` again, which reads Q3. `ThreadBox` in `RequestDetail.tsx` shows the reply. `TurnLabel` shows the new `awaiting` value.

### 4.3 A fetch moves the default branch

1. A trigger calls `FetchRepository::request()`: the Refresh button through `RepositoryController::fetch()` (R5), or a GitHub push through `GitHubRequestParser`, `GitHubWebhookConsumer::consume()`, a `GitHubPushJob` with a `TenantStamp`, and `GitHubPushHandler::__invoke()`.
2. `FetchRepository::request()` calls `RepositoryRepository::requestFetch()`, a conditional UPDATE. Only the winner dispatches `FetchRepositoryJob`, then `RepositoryChanged`. `PublishRepositoryChanges` publishes a `repository` update.
3. In the worker, `RepositorySyncHandler::handleFetch()` calls `clearFetchRequest()`, waits for the lock in `RepositoryLock::acquire()`, calls `startFetch()`, then `ServerClone::fetch()`.
4. `ServerClone::fetch()` builds `FetchCommand::gitHub()` or `FetchCommand::localDirectory()` and calls `BareClone::fetch()` with the SHAs of pending links. It reads `GitReader::headBranch()` and `resolveCommit()`, and calls `RepositoryRepository::recordSuccess()` with the new head.
5. `ServerClone::fetch()` returns `RepositoryFetched` with the previous head, from the entity read before the fetch, and the new head. The lock is released.
6. `ServerClone::synced()` dispatches `RepositoryFetched`, then `RepositoryChanged`.
7. `ResolvePendingCommitLinks::onRepositoryFetched()` pins each found commit with `PinRefs::pinCommit()` and writes `commit.found` per review through `CommandExecutor::execute()` with `Actor::system()`.
8. `QueueLocationRecompute::onRepositoryFetched()` returns when the two heads are equal. Otherwise it dispatches `RecomputeLocations` for each open review.
9. `RecomputeLocationsHandler::__invoke()` gets the head from `FetchRepository::cloneReady()->getDefaultBranchHead()`. For each request it calls `Locator::locate()` with the `request.opened` event ID from `ReviewEventRepository::findOpenedEventIds()`.
10. `Locator::locate()` reads `LocationCacheEntryRepository::findOneByKey()`. A new head is a new key, so it calls `compute()` and stores the result with `LocationCacheSql::insert()`.
11. The handler sets `outdated` to true for the status `changed` or `orphaned`, else to false, with `ChangeRequest::recordOutdated()`. A failure keeps the old flag. No head gives null. It flushes, then publishes `outdated` with the IDs of the changed requests only.
12. The browser gets `outdated` for the open review and revalidates. `OutdatedLabel` changes. `RequestQuery::get()` reads the location at the new head, from the cache that step 10 filled.

## 5. Cross-cutting rules

| Rule | How the code does it | Enforced by |
|---|---|---|
| Tenancy | `TenantSqlFilter` adds `tenant_id = :tenant` to each ORM query on a tenant table and throws `MissingTenantContext` without a context. `RepositoryScopeSqlFilter` applies a token scope and throws for an entity without a rule. `TenantContext::reset()` runs after each worker request. | `tests/Functional/Tenancy/`, `Audit/CrossTenantCoverageTest.php`, `Api/CrossTenantRouteInventoryTest.php` |
| Context sources | Session: `TenantUserProvider` and `TenantContextListener`. Bearer: `ApiTokenHandler`. Messenger: `TenantContextMiddleware` reads `TenantStamp` and clears the entity manager. It refuses a message without a stamp, unless its class is in `app.tenantless_messages`. Console: a tenant argument or option. | `TenantContextResetTest`, `Messenger/MessengerTenantContextTest.php` |
| `TenantFilter::suspend()` | 9 calls in 8 files, in the four places of architecture section 4. Sign-in and token lookup: `TenantUserProvider`, `GitHubUserProvider`, `LocalUserProvisioner`, `ApiTokenHandler` (2 calls). Webhook router: `GitHubWebhookConsumer`. Projection rebuild: `RebuildProjectionsCommand`. Purge: `DeletionHandler`. Also `E2eSignInController`, in `e2e` only. | `TenantFilterSuspendTest` proves that the filter comes back. No test limits the call sites. |
| Raw SQL only in `*Sql` classes | `EventStoreSql`, `ReadSnapshotSql`, `DeletionSql`, `ViewedFileSql`, `IndexSql`, `LocationCacheSql`, `WebhookDeliverySql`. Each statement on a tenant table names its tenant column. The SQL filters do not see raw SQL. | `Audit/RawSqlTenantAuditTest.php` |
| Git only through `GitRunner` | `GitRunner` is the only class that creates a `Process`. `CatFileBatch` uses `GitRunner::start()`, and each `StreamedBlob` reads through a `CatFileBatch` of its own. Arguments are arrays with a fixed environment. Tokens reach git only through `GIT_CONFIG_*` variables and are redacted from errors (D38). Paths come from IDs through `TenantDataPaths`. | `Unit/Conventions/NoProcessOutsideGitTest.php`, which allows a `Process` only in `src/Git` |
| Locks | Event writes: the tenant row `FOR UPDATE`. `symfony/lock` keys on PostgreSQL advisory locks, all with no TTL: `fetch:<repositoryId>`, `github-repositories:<tenantId>`, `drafts:<reviewId>:<userId>`, `mcp-session-<id>`, `connect-local:<tenantId>:<directory>`, `api-token-issue:<userId>`. Deletes lock parent rows `FOR UPDATE`. Index and cache writes lock them `FOR KEY SHARE`. | `GaplessSequenceTest`, `Deletion/` tests |
| Dispatch outside transactions | `CommandExecutor::transactional()` and `FetchRepository::refuseOpenTransaction()` throw inside an open transaction. `EventsCommitted` follows the commit. `RepositoryFetched` follows the lock release. Pins are written before the transaction and removed on failure. | `PostCommitNotificationTest`, `StartReviewPinRollbackTest` |
| Fresh reads | Reads that decide something use `findFresh()` with `Query::HINT_REFRESH`. A plain `find()` can return an entity that the request loaded before another process committed. | `EventStore/FreshStateTest.php` |
| Live updates carry IDs only | `LivePublisher::publish()` runs after the flush or commit. The browser reads the data again through REST (D42). A failed publish is logged and not retried. | `Live/PublishAfterCommitTest.php`, `Live/HubIsolationTest.php` |
| Same JSON for REST and MCP | Both adapters call the same services and use `App\Serializer\Normalizer` (D37). Every response validates against `docs/specs/schemas/`. | `Contract/` and `Mcp/` tests |

## 6. Tests

| Suite | Location | Tool and base | What it proves | Run |
|---|---|---|---|---|
| Unit | `tests/Unit/`, 61 classes | PHPUnit, `KernelTestCase` without boot | Value objects, the transition matrix, `awaiting` rules, event codecs, pure anchoring algorithms. `Conventions/` holds the meta-tests, for example domain purity, 800-line limit, no container mocks, TestDox on every test (D44). | `docker compose exec php bin/phpunit --testsuite unit` |
| Functional | `tests/Functional/`, 149 classes | `KernelTestCase` and `WebTestCase` on `app_test`, real git repositories in temporary directories, `MockHttpClient` for GitHub | Event store and projections, re-anchoring cases F1 to F14, tenant isolation, REST and MCP contracts against the schemas, webhooks, deletion, the headless slice | `make test` runs unit and functional |
| Front-end unit | `assets/**/*.test.ts`, 19 files | Vitest | Pure TypeScript: live subscription, helpers, enum arrays and fixtures against the schemas | `make front` |
| UI | `tests/ui/*.spec.ts`, 29 specs | Playwright Test on the host, against the SPA built by `tests/ui/vite.config.ts` | Screen flows with no PHP, database, or git. `tests/ui/support/mockApi.ts` answers `/api/v1`, and each mocked body validates against the schemas. A fake `EventSource` sends live updates. | `make ui` |
| Full-stack E2E | `tests/E2E/`, 6 classes | `FullStackTestCase` in `tests/Support/E2E/` with playwright-php, against the `e2e` site on port 8081 and `app_e2e` | Sign-in, the vertical slice through browser and MCP, a live agent reply, account deletion, a scoped token | `make e2e` |

Run `make db-test` once and after each migration. `make check` runs all gates. One PHP test: `docker compose exec php bin/phpunit --filter StartReviewTest`. One UI spec: `npx playwright test tests/ui/LineComment.spec.ts`. One Vitest file: `npx vitest run assets/live`. Shared PHP fixtures and helpers are in `tests/Support/`.

## 7. Complexity ledger

"Forced by" means a spec, a decision, or a test requires the shape. "Candidate" means a smaller shape does the same job. No code was changed for this ledger.

| Area | Size | Verdict | Evidence, and a simpler shape |
|---|---|---|---|
| Event log, deciders, reducers, projectors | `Domain\Request`, `Domain\Event` with sub-namespaces, `EventStore`, `Projection`: about 2,800 lines | Forced by D3, D37, architecture section 6, and the domain purity rule of plan M02 | 14 event classes for 14 types, explicit codecs, and a transition test with 192 cases. The cost: one request exists as events, `RequestState`, `ChangeRequest`, and a view DTO. A new request field touches `RequestState`, `RequestReducer`, `ChangeRequest`, and `RequestStateMapper`. |
| `src/Mcp/BundledSchemas.php` | 2,472 lines | Forced by `specs/mcp.md` section 3 | `#[McpTool(outputSchema:)]` takes only constant expressions. `app:schema:bundle` generates the file, and `BundledSchemasUpToDateTest` checks it. |
| MCP tools next to REST actions | `Mcp\Tool`: 12 classes, about 680 lines | Forced by architecture section 7.1 and D20 | Each tool closure equals one REST action line. One class per tool is a choice. Check the SDK discovery before you merge classes. |
| Normalizers and view DTOs | 17 normalizers with 1,151 lines, 26 DTO and enum files in `Application\Query` with 722 lines | Forced by D37 and architecture section 7.2. Candidate in part. | Two serialization paths: DTOs such as `RequestCommandResult`, `UpdatesPage`, `RepositoryPage`, and `IndexStatus` have no normalizer and go through the framework `ObjectNormalizer`. Many DTOs wrap an entity and add a few fields. Simpler: one rule for all responses. |
| `Application\FetchRepository` | 462 lines, 17 constructor parameters, 3 message handlers | Candidate. The lock and sync rules are forced by D24 and D39. | Done in S05. `FetchRepository` has 203 lines and 9 constructor parameters: it queues clones and fetches, fetches within 60 s, removes access, and checks that a clone is ready. `ServerClone` fetches from the source, with the GitHub token request and the local discovery. `RepositorySyncHandler` runs the clone and fetch jobs, `DeletionHandler` the clone deletion and the tenant purge, and `RepositoryLock::acquire()` waits for the lock for all of them. Both sources build one `Source\FetchCommand`. An interface with a picker in `App\Source` is not smaller, because only `ServerClone` picks. Remains: `ServerClone` has 12 constructor parameters, which the two sources need. |
| Re-anchoring | 4 namespaces, about 1,380 lines | Forced by architecture sections 5.4 and 5.5 and plan M28. Candidate for names. | Done in S06. The pure algorithms are in `App\Domain\Matching`, and `App\Anchoring` holds the git and cache side. `Locator` is the only production caller of `Domain\Matching`, and the split lets the algorithms run in unit tests without git. The value types `ExactMatch`, `FuzzyMatch`, and `MappedRange` stay, because a fold gives an array shape or a second similarity run, and `Hunk` holds the boundary rules of `RangeMapper`. |
| Tenancy | `Tenancy`, 7 `*Sql` classes, `findFresh()` methods | Forced by D3, D17, architecture section 4, plan M14 | Each part closes a gap that the ORM filter leaves: DBAL queries, identity-map hits, scoped tokens. |
| Git layer | `App\Git`: 15 files, 1,193 lines | Forced by architecture section 4, D26, D38, D39. Small candidates. | Done in S03. `CatFileBatch` holds the one header parse, read loop, and 60 s timeout, and `StreamedBlob` reads through a `CatFileBatch` of its own. `GitReader::blobAt()`: done in S01. |
| Single-consumer services | `ApiTokenIssuer`, `ConnectLocalRepository`, `CommitAvailability`, `TreeQuery`, `EventLogQuery` | Candidate | Done in S02. The first four live in their only consumers: `TokenController::create()`, `RepositoryController::connectLocal()`, `RequestQuery::target()`, and `CodeController::listTree()`. `EventLogQuery`: done in S01, the tests read events through `tests/Support/EventStore/EventLog.php`. |
| Wrapper use cases | `Application\LinkCommits`, `UnlinkCommit`, `MarkReadyForReview` | Forced by D26 and the `CommandExecutor` transaction rule. Candidate for names. | Git work stays outside the event transaction, so each wraps one `RequestCommands` method. The class names repeat `Domain\Request\Command` names, so imports need aliases such as `LinkCommitsCommand`. |
| Message handlers in three places | 4 methods in `Application`, 2 classes in `MessageHandler`, 7 methods in `Webhook\Handler` | Candidate | Done in S05. Every handler is in `App\MessageHandler`, and no message docblock names its handler. |
| Repeated helpers | 4 cursor paginations, 5 `uuid()` helpers | Candidate | Done in S03. `Cursor::decode()` checks the sort key types and `Cursor::next()` makes `nextCursor` for `RequestQuery`, `ReviewQuery`, `RepositoryQuery`, and `CodeController`. Raw SQL, the SQL filter parameters, and list parameters convert a typed ID with `Identifier::toRfc4122()`. Each list keeps its own `ORDER BY` and keyset condition. |
| Typed ID conversions in entities | 17 entities, 41 `Ulid::fromString()` calls in `Entity` and `Repository` | Candidate. Typed IDs on `ulid` columns are a choice of plan M06. | Each getter converts a stored `Ulid` to a typed ID. Simpler: one DBAL type per ID class. |
| Small Domain items | `Domain\Draft`, `Domain\User`, `ActorCodec`, DBAL types | Candidate, low cost | Done in S01, with the entity getters that nothing calls, and the `equals()` methods and `Domain\Matching` helpers that no production code calls. `CommitShaType` and `BlobShaType` stay two classes: each returns its own value class, and DBAL registers a type by class without arguments. Seven string value objects repeat `toString()`, and three of them `equals()`. |
| Symbol index | `App\Index`: 7 files, 531 lines | Forced by architecture sections 4 and 7.1. Small candidate. | `SymbolExtractor` has one implementation, but section 7.1 lists it as a port. The `indexed_file` table is required by section 4, and only `IndexSql::storedSymbolCounts()` reads it. `IndexedFileRepository`: done in S01. |
| GitHub integration | `GitHub`, `Webhook`: 15 files, about 1,300 lines | Forced by `specs/rest-api.md` R2 and `specs/webhooks-and-live-updates.md` section 1. Candidate in part. | Done in S11. `RepositoryRepository::nameAfterRename()` holds the rename-collision rule, and the GitHub payloads go straight through `DenormalizerInterface::denormalize()`. Remains: the tenant lock block in four classes, because a shared helper is not smaller, and `InstallationSync`, which writes entities and calls `FetchRepository` from an integration namespace. |
| `CommitDiffController` | 124 lines | Candidate | It calls `GitReader`, `Locator`, and repositories directly and sorts files itself. Other reads put this in `Application\Query`. `RequestChanges.tsx` sorts the same list again. |
| Front end | `assets/`: about 6,500 lines | Forced by D1, D29, architecture sections 8 and 12. Small candidates. | Done in S12. Remains: a file view costs `fileThreads()` in `fileData.ts` one Q1 call per 200 requests under the path prefix, plus one Q3 call per request on the file, because only Q3 returns a thread; `RequestChanges.tsx` sorts the files again, because Q9 does not define their order. |

## 8. Where the rest is documented

| Topic | Document |
|---|---|
| Product requirements and non-goals | [requirements.md](requirements.md) |
| Domain model, value objects, entities, events | [architecture.md](architecture.md) section 3 |
| PostgreSQL, write transaction, tenant isolation, server clone, fetch triggers, deletion | [architecture.md](architecture.md) section 4 |
| Capture, targets, statuses, algorithm, failure cases F1 to F14 | [architecture.md](architecture.md) section 5 |
| Request lifecycle, `awaiting` rules, review lifecycle | [architecture.md](architecture.md) section 6 |
| Interfaces, error codes, MCP, REST, live updates, console | [architecture.md](architecture.md) section 7 |
| Web UI screens and flows | [architecture.md](architecture.md) section 8 |
| Test strategy, decisions D1 to D44, risks, recorded deviations | [architecture.md](architecture.md) sections 10 to 13 |
| Conventions, authentication, error check order, pagination, caching | [specs/README.md](specs/README.md) |
| Endpoints A, R, V | [specs/rest-api.md](specs/rest-api.md) |
| Endpoints Q, D, T, shell routes | [specs/rest-api-requests.md](specs/rest-api-requests.md) |
| MCP tools and server instructions | [specs/mcp.md](specs/mcp.md) |
| Webhooks, fetch triggers, Mercure topics and payloads | [specs/webhooks-and-live-updates.md](specs/webhooks-and-live-updates.md) |
| JSON Schemas and fixtures | `docs/specs/schemas/`, `docs/specs/fixtures/` |
| Milestones, workflow, verified versions | [plan/README.md](plan/README.md) |
| Why each milestone came out as it did | [plan/reports/](plan/reports/) |
| Work outside the proof of concept | [backlog/README.md](backlog/README.md) |
| User documentation and the Agent Skill | [user/index.md](user/index.md), [user/skill/SKILL.md](user/skill/SKILL.md) |
| Run the app and the checks | [README.md](../README.md) |
| Symfony conventions | [AGENTS.md](../AGENTS.md) |
