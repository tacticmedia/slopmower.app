# Developer guide

This guide is a map of the Holistic Review code. It tells you what each part does, where it is, how a call moves through it, and which parts are more complex than their task needs (section 7). The [architecture](architecture.md) and the [specifications](specs/README.md) stay the sources of truth. This guide points to them.

Size: `src/` has 380 PHP files and about 24,700 lines. One generated file, `src/Mcp/BundledSchemas.php`, has 2,472 of these lines. `assets/` has 128 TypeScript files and about 6,500 lines without tests. `tests/` has about 23,000 lines of PHP and 2,800 lines of Playwright UI tests.

## 1. Start here

### 1.1 The product

Holistic Review lets you review a full codebase at one commit, in a view like a GitHub pull request review, and each comment is a change request. Your coding agent reads the requests over MCP, does the work, and links its commits, and you verify or reopen each request in the browser.

### 1.2 Runtime pieces

| Piece | What runs there | Entry code |
|---|---|---|
| `php` container | FrankenPHP in worker mode. It serves the HTML shell, the REST API `/api/v1`, the MCP endpoint `/_mcp`, the user docs `/docs`, the GitHub webhook `/webhook/github`, and the built-in Mercure hub. It also serves the `e2e` site on port 8081 in classic mode (D34). | `src/Controller/`, `src/Mcp/`, `src/Webhook/` |
| `worker` container | `messenger:consume async`, same image and volumes. It clones and fetches, builds the symbol index, computes locations again, applies webhook jobs, and purges tenants. | `src/MessageHandler/`, `src/Message/` |
| `database` container | PostgreSQL 18. It holds all rows, the Messenger queue `doctrine://`, the browser sessions, and the advisory locks of `symfony/lock` (D39). | `src/Entity/`, `migrations/` |
| Host | Node.js 24 and Vite build `assets/` for the shell page. `npm run dev` gives hot reload. | `assets/`, `vite.config.ts` |
| Tenant data | One bare git clone per tenant and repository at `$APP_TENANT_DATA_DIR/<tenantId>/repositories/<repositoryId>.git`. | `src/Git/TenantDataPaths.php` |

Worker mode keeps services in memory between requests. Services keep no state between requests. The two services with state, `TenantContext` and `GitReader`, implement `ResetInterface`, and the kernel resets them after each request and each message.

### 1.3 Three core concepts

1. **One event log, read through projections.** Each change to review data is an event row in `review_event`. `App\EventStore\CommandExecutor::execute()` is the only write path for these events. It locks the tenant row and runs the decide closure of the caller, which calls a pure decider from `src/Domain` for every event except `commit.found`, `batch.submitted`, and the first comment of a carried request. `ResolvePendingCommitLinks` builds `commit.found`, `DraftController::submit()` builds `batch.submitted`, and `CarryOverController::carry()` builds the `request.commented` of that first comment. It appends the events with a gapless per-tenant `seq`, updates the projection tables in the same transaction, commits, and then dispatches `EventsCommitted` when it wrote events. Most reads use the projection tables `review`, `change_request`, `comment`, and `commit_link`. Thread, update, and commit diff reads also read `review_event`. See D3 and architecture section 4.
2. **A change request is anchored to code at one commit.** A review pins one commit. A request stores an `Anchor` that the server captured at that commit: path, blob, a line range, the symbol FQN for a symbol anchor, and a snapshot of the lines. A file anchor has no range and no snapshot. A general request has no anchor: it belongs to the whole review and is never located (D45). When the code changes, the server computes a `Location` at another commit. This is re-anchoring. A location is cached, never an event. The `outdated` flag of a request comes from the location at the default-branch head. See architecture section 5.
3. **Tenant isolation fails closed.** Code that reads tenant data runs with a tenant context. The context comes from the signed-in user, the API token, the tenant stamp of a Messenger message, or a console argument or option. A Doctrine SQL filter adds `tenant_id = :tenantId`, or `id` on `tenant`, to each ORM query on a tenant table and throws when no context is set. Raw SQL exists only in `*Sql` classes, and each statement on a tenant table names its tenant column. Tests enforce both rules. See D3, D17 and architecture section 4.

REST and MCP are adapters over one application layer, and they return the same JSON (architecture section 7.1). A read or logic that only one REST action needs stays in its controller.

### 1.4 Reading order

First read architecture sections 3 and 6. `src/Domain` implements them almost line for line. Then read these files in this order:

| # | File | Why |
|---|---|---|
| 1 | `src/Domain/Request/RequestState.php` | The folded state of one request and its invariants |
| 2 | `src/Domain/Request/AllowedActions.php` | Who may do what in which status. The decider and the API responses use the same function. |
| 3 | `src/Domain/Request/RequestDecider.php` | Command and state give events or a `DomainError`. The business rules. |
| 4 | `src/Domain/Request/RequestReducer.php`, `AwaitingPolicy.php` | How an event changes the state, and whose turn it is |
| 5 | `src/EventStore/CommandExecutor.php` | The only write path for events, 171 lines |
| 6 | `src/Projection/RequestProjector.php` | How events become `change_request`, `comment`, and `commit_link` rows |
| 7 | `src/Application/RequestCommands.php` | How each request command gets to the decider |
| 8 | `src/Mcp/McpTools.php`, method `claimRequest()`, and `src/Controller/Api/AgentRequestController.php` | One command through MCP and through REST |
| 9 | `src/Tenancy/TenantContext.php`, `TenantSqlFilter.php`, `TenantFilter.php` | Tenant isolation |
| 10 | `src/Application/FetchRepository.php`, `ServerClone.php` | The server clone. `FetchRepository` queues each clone and fetch, fetches within 60 s, and removes access. `ServerClone` clones and fetches from the source. |
| 11 | `src/Anchoring/Locator.php`, method `compute()` | Re-anchoring. Read it next to architecture section 5.4. |
| 12 | `assets/router.tsx`, `assets/live/useLiveRevalidation.ts` | The front end: routes with loaders and actions, and the one live subscription |

Skip these on a first pass: `src/Mcp/BundledSchemas.php`, `assets/api/types/`, `src/Doctrine/Query`, `src/GitHub/Payload`, `src/Git/CatFileBatch.php`, and `src/Git/StreamedBlob.php`. The first file is generated, and the TypeScript types copy the JSON Schemas.

## 2. Domain map

### 2.1 Domain areas

**Repositories and the server clone.** A tenant connects a repository from a GitHub App installation or from a local directory under `APP_LOCAL_REPOSITORIES_DIR` (D24). The server keeps its own bare clone of each repository and reads code only from that clone. `SyncState` is the clone state: `cloning`, `idle`, `fetching`, `failed`, or `unavailable`. Pin refs under `refs/holistic-review/` keep reviewed and linked commits through force-pushes and `git gc` (D26). Code: `Application\FetchRepository`, `Application\ServerClone`, `MessageHandler\RepositorySyncHandler`, `App\Git`, `App\Source`, `App\GitHub`, `Repository\RepositoryRepository`.

**Reviews.** A review is one feedback round on one repository at one pinned commit. The start resolves the ref to a full SHA, and that SHA never changes. A review stays open until a user closes it. A closed review is read-only (D27). Two open reviews of one repository are fully separate. Code: `Domain\Review`, `Application\ReviewCommands`, `Entity\Review`.

**Change requests.** A request is one thread that asks for one change. Only a user opens a request. No MCP tool opens one, and `RequestDecider` refuses an `Open` from an agent. The six statuses are `open`, `in_progress`, `ready_for_review`, `wont_do`, `verified`, and `reopened`. An agent claims a request, replies, proposes a solution, links commits, and marks it ready or won't do. The user approves or rejects a proposal, and verifies or reopens the request. `awaiting` tells whose turn it is. The entity is `ChangeRequest`, and the table is `change_request`. Code: `Domain\Request`, `Application\RequestCommands`, `Entity\ChangeRequest`.

**Commit links.** An agent links a full commit SHA to the requests that it holds. A link is `pending` until the server clone has the commit, then `available`. After a fetch, `ResolvePendingCommitLinks` writes one `commit.found` event per found commit and review with the system actor. `ready_for_review` needs all links to be available (D19). Code: `RequestCommands::linkCommits()`, `Application\ResolvePendingCommitLinks`, `Entity\CommitLink`.

**Drafts and viewed files.** These are private state of one user in one review. They are not events. A draft is a pending new request or reply (D15). A user has at most 500 drafts per review. A submit turns all drafts into events of one batch in one transaction. A viewed file is the "Viewed" mark of a file. Code: `Entity\Draft`, `Entity\ViewedFile`, `Controller\Api\DraftController`.

**Code reading and the symbol index.** The server reads the review commit from its clone: `git ls-tree` for trees, one `git cat-file --batch` process per clone for content, and one more per streamed raw blob. At review start, a Messenger job writes the path list and the PHP symbols of the commit. Symbols give symbol anchors, the "Symbols" menu, and code navigation: V14 resolves the name at a position, or a typed name, with php-parser's `NameResolver` and looks up its declarations by FQN among the symbols of the commit. There is no reference index. Quick open, V16, finds the paths and symbol names of the commit that contain a text or match it by word starts: one SQL query on the index selects the candidates, and `Index\SearchRank` ranks them. The index belongs to a commit, so reviews of one commit share it. Code: `Git\GitReader`, `App\Index`, `MessageHandler\IndexReviewHandler`, `Controller\Api\DeclarationController`, `Controller\Api\SearchController`.

**Identity, tokens, and tenants.** A tenant is one developer account, with one user per tenant (D17). A browser user signs in with GitHub (D23), or with local sign-in in `dev` only (D25). An agent sends a per-user API token `hr_...` and acts as `agent`, with the token name as agent name (D13, D30). A token can be limited to one repository. Code: `App\Security`, `App\Tenancy`, `Entity\Tenant`, `Entity\User`, `Entity\ApiToken`.

**Live updates.** After a commit, the server publishes a private Mercure update that holds IDs only (D42). The browser keeps one `EventSource` and revalidates its React Router loaders. The agent gets no push. It polls `get_updates` (D21). Code: `App\Live`, `assets/live/`.

**Deletion.** A user deletes a review, the data of one repository, or the account. Review and repository rows go in the HTTP request under the tenant row lock. Clones and account data go in Messenger jobs. When GitHub removes access, the server deletes the clone and the index and keeps the reviews (D22). Code: `Repository\DeletionSql`, `MessageHandler\DeletionHandler`, `FetchRepository::removeAccess()`.

### 2.2 Request lifecycle at a glance

| Command | From | To | Who |
|---|---|---|---|
| `open` | none | `open` | User |
| `claim` | `open`, `reopened` | `in_progress` | Agent |
| `release` | `in_progress` | The status before the claim | Holder, or user with "Unassign" |
| `link_commits` | `in_progress` | Unchanged | Holder, with the proposal gate open |
| `unlink_commit` | `in_progress` | Unchanged | Holder |
| `link_pull_request` | `in_progress`, `ready_for_review` | Unchanged | Holder with the proposal gate open while `in_progress`, any agent while `ready_for_review`. No agent event for `awaiting`. |
| `unlink_pull_request` | `in_progress`, `ready_for_review` | Unchanged | As `link_pull_request`, without the proposal gate |
| `mark_ready_for_review` | `in_progress` | `ready_for_review` | Holder, with the proposal gate open. All links must be available. |
| `mark_wont_do` | `open`, `reopened`, `in_progress` | `wont_do` | Agent, the holder when `in_progress`, or user. The reason has 20 or more characters. |
| `verify` | `ready_for_review`, or `wont_do` set by an agent | `verified` | User |
| `reopen` | `ready_for_review`, `wont_do`, `verified` | `reopened` | User |
| `reply` | Any | Unchanged | User or agent. An agent can set `question`. |
| `propose_solution` | `in_progress` | Unchanged | Holder. A new proposal replaces the current one. |
| `approve_proposal`, `reject_proposal` | `in_progress` with a pending proposal | Unchanged | User |
| `set_plan_requirement` | `open`, `reopened`, `in_progress` | Unchanged | User. The current value writes no event. |

A closed review allows no request command. Architecture section 6 has the full table and the `awaiting` rules, and `AllowedActions::permits()` is the same table in code, except `open`, which `RequestDecider` checks. The 21 event types are `review.started`, `review.closed`, `request.opened`, `request.commented`, `request.claimed`, `request.released`, `request.ready_for_review`, `request.wont_do`, `request.verified`, `request.reopened`, `request.proposed`, `request.proposal_approved`, `request.proposal_rejected`, `request.plan_required`, `request.plan_waived`, `commit.linked`, `commit.unlinked`, `commit.found`, `pull_request.linked`, `pull_request.unlinked`, and `batch.submitted`.

### 2.3 What "anchoring" means

The term has two meanings:

| Job | When | Input | Output | Code |
|---|---|---|---|---|
| Capture | A user opens a request or saves a draft with an anchor. A general request skips it. | Kind, path, lines or symbol FQN | `Anchor`, with a `ContentSnapshot` except for a file anchor, stored in `draft.anchor`, or in `request.opened` and `change_request.anchor` | `Application\AnchorFactory::capture()`, `Domain\Anchor\SnapshotCapture::capture()` |
| Re-anchoring | A caller needs the anchor at another commit: the default-branch head, an `at` commit, or a linked commit | `Anchor` and a target SHA | `Location` with status `exact`, `shifted`, `changed`, or `orphaned` | `App\Anchoring\Locator::locate()` |

The code sits in `Application\AnchorFactory`, which starts a capture, and in four namespaces:

```text
App\Domain\Anchor      Anchor value object, snapshot capture, comparison form, normalized hash
App\Domain\Matching    pure algorithms on line arrays: diff parser, range mapper, matcher, similarity, best window
App\Domain\Location    the result: Location, LocationStatus, OrphanReason, LocationTarget
App\Anchoring          git and cache side: Locator, LocationCacheSql, CodeExcerpt
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
| Proposal | The current plan of a claim, with an optional unified diff: `pending`, `approved`, or `rejected`. Release, won't do, and reopen clear it. The proposal gate is open when it is null or approved, and approved when the request asks for a plan first (D49). | `Domain\Request\Proposal`, `ProposedDiff`, `AllowedActions::permits()` |
| Ask for a plan first | `requireApproval`: the request needs an approved proposal before `link_commits` and `mark_ready_for_review`. Set when the request opens. The user changes it with `set_plan_requirement` while the request is `open`, `reopened`, or `in_progress`. | `Domain\Request\RequestState::$requireApproval`, `assets/comments/CommentBox.tsx`, `assets/requests/RequestActions.tsx` |
| Resolution | `fixed` after `ready_for_review`, `wont_do` after `wont_do`. `reopen` clears it. | `Domain\Request\Resolution` |
| Commit link | A commit linked to a request, `pending` or `available` | `Entity\CommitLink`, `Domain\Request\LinkedCommit` |
| `commit.found` | System event that makes pending links available after a fetch. No Domain decider writes it. | `Application\ResolvePendingCommitLinks::recordFound()` |
| Availability | The commit in the clone that `link_commits` gives to the decider, so the link is `available` at once. Only `link_commits` passes it. | `RequestDecider::decide()` parameter `$availability` |
| Actor | `user`, `agent`, or `system`. Two tokens of one user with the same name are the same agent. | `Domain\Actor\Actor`, `Security\CurrentActor::get()` |
| Event | One `review_event` row. 20 types. Stored events are never rewritten. | `Domain\Event\EventType` |
| `seq` | Gapless event number per tenant, from `tenant.event_seq` under `FOR UPDATE`. The `get_updates` cursor and the last segment of the Mercure ID of an `events` update. | `EventStore\EventStoreSql::lockTenant()` |
| Decided or recorded event | `DecidedEvent` is a decider result. `RecordedEvent` is the stored event with ID, `seq`, actor, and time. | `Domain\Event\DecidedEvent`, `RecordedEvent` |
| Decider | Pure static function: command and state give events, or a `DomainError` | `RequestDecider::decide()`, `ReviewDecider::decide()` |
| Reducer | Pure static function: state and event give the next state | `RequestReducer::apply()`, `ReviewReducer::apply()` |
| Projection | A read table that changes in the transaction of its events. `app:projections:rebuild` rebuilds it. | `Projection\RequestProjector`, `ReviewProjector` |
| Not event data | Data that a rebuild keeps: `outdated`, the index columns of `review`, drafts, viewed files | Docblocks of `Entity\Review`, `ChangeRequest`, `Draft` |
| Fresh read | A query with `Query::HINT_REFRESH`, so a stale identity-map entity does not win | `*Repository::findFresh()` |
| Anchor | Where a request points at the review commit. Null for a general request. | `Domain\Anchor\Anchor` |
| Anchor kind | `line`, `range`, `symbol`, or `file`. A symbol range includes the doc comment (D7). A file anchor has no range and no snapshot. | `Domain\Anchor\AnchorKind` |
| General request | A request with a null anchor that belongs to the whole review. No location, no excerpt, `outdated` null. No file view shows it, and a `path` filter never matches it (D45). | `Domain\Request\Command\Open`, `RequestQuery` |
| Carry-over | V15 copies the `open` and `reopened` requests of another review of the repository into a review, re-anchored at its commit. A copy names its source in `carriedFrom`, and the source review never changes (D47). | `Controller\Api\CarryOverController`, `Domain\Request\CarriedFrom` |
| Content snapshot | The anchored lines, all up to 200, else the first 100 and the last 100. Also up to 3 context lines on each side and `normalizedHash`. | `Domain\Anchor\ContentSnapshot` |
| Comparison form | A line trimmed, with inner whitespace runs collapsed to one space. All content comparisons use it. | `SnapshotCapture::comparisonForm()` |
| Distinctive snapshot | 8 or more non-whitespace characters. Only these get a content search. | `Domain\Matching\SnapshotMatcher::isDistinctive()` |
| Hunk, mapped range | A `--unified=0` diff block, and the anchor range moved through the hunks. `untouched` means no hunk changes the region. | `Domain\Matching\RangeMapper::mapRange()` |
| Location | Where an anchor is at a target commit: status, path, range, confidence, orphan reason | `Domain\Location\Location` |
| Location status | `exact`, `shifted`, `changed`, `orphaned` | `Domain\Location\LocationStatus` |
| Orphan reason | `file_deleted`, `region_deleted`, `ambiguous`, `content_not_found` | `Domain\Location\OrphanReason` |
| Location target | `default_branch`, `requested` for the `at` commit, `linked_commit` | `Domain\Location\LocationTarget` |
| Outdated | True when the location at the default-branch head is `changed` or `orphaned`. Null without a clone or a default branch. | `ChangeRequest::recordOutdated()` |
| Draft, batch | Pending new requests and replies of one user, submitted together with `batch.submitted` | `Entity\Draft`, `DraftController::submit()` |
| Renderable file | A regular file of at most 1 MiB with no NUL byte in the first 8000 bytes | `Git\TreeEntry::isRenderableFile()`, `Git\Blob::isBinary()` |
| Snapshot, three meanings | `ContentSnapshot` holds code lines. `ReviewSnapshot` is folded review state. `ReadSnapshotSql` runs a REPEATABLE READ transaction. | `Domain\Anchor`, `Domain\Review`, `EventStore` |
| Live update | Private Mercure message with IDs only: `events`, `outdated`, `index`, `repository`, `repository_deleted`, `candidates_changed` | `Live\LivePublisher`, `assets/api/types/live-update.ts` |
| `*Sql` class | The only kind of class that may run raw SQL | `RawSqlTenantAuditTest` |
| Schema-shaped | A response class whose public properties are the fields of its schema, in schema order. `ObjectNormalizer` writes it. A class that cannot be has one normalizer named after its schema (section 5). | `Application\Query\IndexStatus`, `LocationView`, `Anchoring\CodeExcerpt` |
| Spec IDs | Endpoint IDs in comments: A account, R repositories, V reviews and code, Q requests, D drafts, T tokens. Endpoint IDs D1 to D5 collide with the decisions Dnn of architecture section 11. | `specs/rest-api.md` section 2, architecture section 11 |

## 3. Namespace map

### 3.1 PHP namespaces

A row counts the PHP files of its namespace and of the sub-namespaces without a row of their own. Dependencies are the other rows whose classes the code of a row names: a `use` import or a fully qualified name, not a `use` that serves only a `{@see}` link. They are written without `App\`. `Domain` stands for any `App\Domain` namespace. A row without one names its main library.

| Namespace | Files | Purpose | Key types | Dependencies |
|---|---|---|---|---|
| `App` | 1 | Kernel. Allows the `e2e` environment. Makes the HWIOAuth GitHub resource owner non-shared. | `Kernel` | FrameworkBundle |
| `App\Domain\Request` | 18 | Request state machine: decide, fold, awaiting, allowed actions, and the errors `InvalidTransition` and `CommitsNotAvailable` of the decider | `RequestDecider`, `RequestReducer`, `RequestState`, `AllowedActions`, `AwaitingPolicy` | Domain only |
| `App\Domain\Request\Command` | 17 | Input DTOs of the 16 request commands | `RequestCommand`, `Open`, `Claim`, `Reply`, `LinkCommits` | Domain only |
| `App\Domain\Review` | 7 | Review start and close, with the same decider and reducer shape | `ReviewDecider`, `ReviewReducer`, `ReviewSnapshot` | Domain only |
| `App\Domain\Event` | 26 | The 21 event types, the two envelopes, and `WontDoReason`, a payload value | `DomainEvent`, `EventType`, `DecidedEvent`, `RecordedEvent` | Domain only |
| `App\Domain\Event\Codec` | 5 | Stored JSON format of payloads (D37). The shared normalizers also use `AnchorCodec` and `TimeCodec`. | `PayloadReader`, `AnchorCodec`, `PayloadJson`, `TimeCodec` | Domain only |
| `App\Domain\Anchor` | 7 | Anchor value object and capture rules | `Anchor`, `ContentSnapshot`, `SnapshotCapture`, `LineRange` | Domain only |
| `App\Domain\Matching` | 9 | Pure re-anchoring algorithms on line arrays | `UnifiedZeroDiffParser`, `RangeMapper`, `SnapshotMatcher`, `BestWindow` | Domain only |
| `App\Domain\Location` | 4 | Result types of re-anchoring | `Location`, `LocationStatus`, `OrphanReason` | Domain only |
| `App\Domain\Error` | 8 | The error codes, their base class, and the errors that no single `Domain` namespace throws | `DomainError`, `ErrorCode`, `InvalidInput`, `NotFound` | Domain only |
| `App\Domain`, `App\Domain\Actor`, `Identity`, `Git`, `Repository`, `ApiToken` | 31 | Value types: actor, ULID IDs, SHAs, paths, refs, repository names, token secret, small enums. The `StringValue` trait of the string value objects. | `Actor`, `Identifier`, `CommitSha`, `RepoPath`, `RefName`, `ApiTokenSecret` | Domain only, and `Symfony\Component\Uid` in `Identity` |
| `App\Application` | 11 | Use cases, the GitHub sync at sign-in, and the listeners after a fetch or a commit. Their git and Messenger side effects run outside the event transaction. | `RequestCommands`, `ReviewCommands`, `FetchRepository`, `ServerClone`, `InstallationSync`, `QueueLocationRecompute` | Application\Event, Application\Query, Domain, Entity, EventStore, Git, GitHub, Index, Message, Projection, Repository, Source, Tenancy |
| `App\Application\Query` | 40 | Read side: 7 query services for the reads that several callers share, `BlobStream` among them, and 33 response DTOs and filter types | `RequestQuery`, `UpdatesQuery`, `ThreadQuery`, `ReviewQuery`, `FileQuery` | Anchoring, Application, Domain, Entity, EventStore, Git, Http, Index, Projection, Repository, Source, Tenancy |
| `App\Application\Event` | 2 | Events after a repository fetch or change | `RepositoryFetched`, `RepositoryChanged` | Domain |
| `App\EventStore` | 4 | Append-only log and the single event write path | `CommandExecutor`, `EventStoreSql`, `EventsCommitted`, `ReadSnapshotSql` | Domain, Entity, Projection, Repository, Tenancy |
| `App\Projection` | 3 | Keeps the read tables in step with events, in the write transaction | `RequestProjector`, `ReviewProjector`, `RequestStateMapper` | Domain, Entity, Repository |
| `App\Entity` | 22 | Doctrine entities, one table each. 16 implement `TenantOwned`, an interface of this namespace that the SQL filters read. The enums `DraftKind`, `SignInMethod`, and `GitHubAccountType`. | `Tenant`, `Repository`, `ReviewEvent`, `Review`, `ChangeRequest`, `CommitLink`, `ApiToken` | Domain, Doctrine, Index, Repository |
| `App\Repository` | 19 | 16 ORM repositories plus `DeletionSql` and `ViewedFileSql`: fresh reads, conditional state updates, deletes. The sign-in finders of `UserRepository` throw `TenantBeingDeleted`. | `RepositoryRepository`, `ReviewEventRepository`, `DeletionSql` | Domain, Entity |
| `App\Doctrine` | 8 | DBAL types for value objects, jsonb DQL functions, `ActorEmbeddable` | `IdentifierType`, `StringValueType`, `AnchorType`, `JsonbContains` | Domain |
| `App\Tenancy` | 6 | Fail-closed tenant context and the two SQL filters | `TenantContext`, `TenantSqlFilter`, `TenantFilter`, `RepositoryScopeSqlFilter` | Domain, Entity |
| `App\Security` | 14 | Session sign-in, bearer tokens, principal to `Actor` | `ApiTokenHandler`, `AgentUser`, `CurrentActor`, `TenantUserProvider` | Domain, Entity, Http, Repository, Tenancy |
| `App\Git` | 15 | The only code that runs git. Server clone reads and writes. | `GitRunner`, `GitReader`, `BareClone`, `PinRefs`, `RepositoryLock` | Domain |
| `App\Source` | 4 | Local discovery and the fetch command of each source | `LocalRepositoryDiscovery`, `FetchCommand` | Domain, Git |
| `App\GitHub` | 6 | GitHub App: JWT, installation tokens, API payloads | `InstallationTokenProvider`, `GitHubApp` | Entity |
| `App\Webhook` | 3 | GitHub webhooks: parse, verify, fan out one job per tenant | `GitHubRequestParser`, `GitHubWebhookConsumer`, `WebhookDeliverySql` | Domain, Entity, GitHub, Message, Messenger, Repository, Tenancy |
| `App\Index` | 11 | PHP symbol extraction and storage per blob. Name resolution and the declaration lookup of code navigation (V14). The file and symbol search of quick open (V16). | `BlobSymbols`, `PhpSymbolExtractor`, `IndexSql`, `Reference` | Domain, Entity, Git, Repository |
| `App\Anchoring` | 3 | Git and cache side of re-anchoring | `Locator`, `LocationCacheSql`, `CodeExcerpt` | Domain, Entity, Git, Index, Repository |
| `App\Message` | 13 | Messenger messages, all on the `async` transport. The GitHub jobs also carry webhook payload fields. | `FetchRepositoryJob`, `IndexReview`, `RecomputeLocations` | Domain, Entity |
| `App\MessageHandler` | 7 | Every Messenger handler: clone and fetch, deletion, index, locations, GitHub webhook jobs | `RepositorySyncHandler`, `DeletionHandler`, `IndexReviewHandler`, `RecomputeLocationsHandler`, `GitHubInstallationHandler` | Anchoring, Application, Application\Event, Domain, Entity, EventStore, Git, GitHub, Index, Live, Message, Repository, Tenancy |
| `App\Messenger` | 2 | Carries the tenant across the Messenger boundary | `TenantContextMiddleware`, `TenantStamp` | Domain, Tenancy |
| `App\Live` | 4 | Mercure publish, IDs only | `LivePublisher`, `LiveTopics`, `PublishCommittedEvents` | Application\Event, Domain, EventStore |
| `App\Controller` | 6 | Shell routes, sign-in pages, `/docs`, `/healthz`, `/github/installed`, e2e sign-in | `ShellController`, `DocsController`, `SecurityController` | Docs, Entity, Repository, Security, Tenancy |
| `App\Controller\Api` | 23 | REST adapter: one action per spec endpoint, with the reads and the logic that no other caller shares | `RequestController`, `RequestCommandController`, `AgentRequestController`, `ReviewController` | Anchoring, Application, Application\Query, Controller\Api\Input, Domain, Entity, EventStore, Git, GitHub, Http, Index, Live, Message, Projection, Repository, Security, Source, Tenancy |
| `App\Controller\Api\Input` | 35 | Request DTOs for `#[MapRequestPayload]` and `#[MapQueryString]` | `AnchorInput`, `RequestListQuery`, `LinkCommitsInput` | Application\Query, Domain, Entity, Http |
| `App\Http` | 7 | The `/api/v1` pipeline: error body, CSRF, body size, headers, cursor, and `QueryInput`, the marker of a query DTO | `ApiExceptionListener`, `ApiCsrfListener`, `Cursor` | Domain |
| `App\Serializer\Normalizer` | 16 | The value normalizers, and one normalizer per schema whose response class cannot be schema-shaped, shared by REST and MCP (D37) | `RequestNormalizer`, `RequestSummaryNormalizer`, `EventNormalizer` | Application\Query, Domain, Entity, Git, Repository, Source |
| `App\Mcp` | 4 | The 15 MCP tools of `specs/mcp.md` section 4 as methods of one class, two session decorators, generated output schemas | `McpTools`, `SessionLockedMcpController`, `TokenBoundSessionStore` | Application, Application\Query, Controller\Api\Input, Domain, Repository, Security |
| `App\Docs` | 2 | Serves `docs/user` and the schemas through a lookup in a directory listing (D28) | `DocsCatalog`, `DocsLinkRewriter` | Finder, CommonMark |
| `App\Command` | 4 | `app:projections:rebuild`, `app:index:rebuild`, `app:tenant:purge`, `app:schema:bundle` | `RebuildProjectionsCommand`, `BundleSchemasCommand` | Domain, Entity, EventStore, Index, Message, MessageHandler, Repository, Tenancy |

### 3.2 Front end

| Folder | Files | Purpose |
|---|---|---|
| `assets/app.tsx`, `router.tsx`, `layout/` | 10 | Entry, the one data router, `RootLayout` with the live hook (D29), `usePreference` for per-browser UI preferences, `useSubmitOnce` for write forms, `modShortcut` for the Cmd or Ctrl keyboard shortcuts |
| `assets/api/` | 33 | `fetch` client with the CSRF header, `ApiError`, TypeScript types written from the schemas |
| `assets/routes/` | 30 | Screens. Loaders read and actions write. Most actions branch on an `intent` field. |
| `assets/code/` | 9 | `CodeFile` and `CommitDiff`, the only wrappers around the pinned `@pierre/diffs` (D1): `CommitDiff.tsx` also exports `ProposalDiff`. Their shared `workerPool` options. |
| `assets/comments/` | 12 | Threads, drafts, comment box, `useOptimistic` updates |
| `assets/live/` | 2 | `LiveSubscription`, the one Mercure subscription, and `EventPositions` |
| `assets/requests/`, `review/`, `settings/` | 53 | Presentational components and pure helpers |

### 3.3 Dependency picture

```text
Adapters      Controller\Api   McpTools   Webhook           Command   MessageHandler
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

`Webhook` and `Command` do not use `Application`: `Webhook` dispatches Messenger jobs, and `Command` calls `EventStore` and `MessageHandler` directly.

- `src/Domain` imports only `App\Domain`, `Symfony\Component\Uid`, and PHP core. `tests/Unit/Conventions/DomainPurityTest.php` enforces it (D44). It has no services and no I/O, and the deciders, reducers, and algorithms are static functions.
- Event data goes only through `CommandExecutor`. Data that is not event data has other writers, for example `IndexReviewHandler`, `RecomputeLocationsHandler`, the conditional updates of `RepositoryRepository`, drafts, viewed files, and tokens.
- No test enforces namespace dependencies outside `src/Domain`. A dependency is a `use` import or a fully qualified name in code, between `App\<Top>` namespaces, or `App\Domain\<Second>` namespaces inside `src/Domain`. A `use` that serves only a `{@see}` link does not count. An exception lives in the namespace of its thrower, and a domain error that no single `Domain` namespace throws lives in `Domain\Error`. These cycles exist, and moving one class to another namespace does not remove any of them. `Entity`, `Index`, and `Repository` form one strongly connected group, because `Index\BlobSymbols` also uses `SymbolRepository` and `IndexedBlobRepository`:
  - `Entity` and `Repository`: `#[ORM\Entity(repositoryClass:)]` names the repository, and the repository returns entities.
  - `Entity` and `Index`: `Symbol::toExtracted()` builds the `Index\ExtractedSymbol` of its row, and `Index\BlobSymbols` reads the symbol entities. The inverse needs a getter per column.
  - `Domain\Error` and `Domain\Identity`: every ID throws `InvalidInput`, and the details of `ReviewClosed` and `RepositoryUnavailable` hold IDs. `ReviewClosed` has throwers in `Domain\Request`, `Domain\Review`, and `Entity`, and only `Application` throws `RepositoryUnavailable`.
- `Mcp\McpTools` validates the Q1 filters with `Controller\Api\Input\RequestListQuery`, so MCP depends on the REST input. The reverse dependency does not exist.

### 3.4 Where a change goes

Change the specs first when a contract changes. The plan forbids a wire format that `docs/specs/` does not define.

| Change | Files to touch |
|---|---|
| A new field on a request | `RequestState`, `RequestReducer`, `ChangeRequest`, `RequestStateMapper`, a migration, `RequestSummaryView` or `RequestView`, its normalizer, the schema, the TypeScript type |
| A new request command | A DTO in `Domain\Request\Command`, an `Action` case, `AllowedActions`, `RequestDecider`, an event class with `toPayload()` and `fromPayload()`, an `EventType` case, `RequestReducer`, `RequestCommands`, one REST action, one MCP tool, and a cross-tenant case for each |
| A new REST endpoint | An action in `Controller\Api`, an input DTO in `Controller\Api\Input`, a service in `Application` or `Application\Query` when another caller shares the logic, a schema-shaped response class or a normalizer (section 5), a cross-tenant case. `CrossTenantCoverageTest` fails without the case. |
| A new MCP tool | A `#[McpTool]` method in `Mcp\McpTools` that calls `run()`, or `requestCommand()` for a request command, sorted by tool name with the other methods, because the method order is the `tools/list` order, a cross-tenant case, and for a new output schema a run of `app:schema:bundle` |
| A new tenant table | An entity that implements `TenantOwned`, a rule in `RepositoryScopeSqlFilter`, the delete lists in `DeletionSql`, a migration. `EntityTenancyConventionTest` checks the tenant column. |
| A new ID class | A subclass of `Identifier` and its line in the `types` of `config/packages/doctrine.yaml` |
| A new background job | A message in `App\Message` with `#[AsMessage('async')]` and a handler in `App\MessageHandler`. Run `make worker-restart` in dev, because the Messenger worker does not reload code. |

## 4. Traced flows

### 4.1 A user starts a review

1. `repositoryAction()` in `assets/routes/repositories/RepositoryRoute.tsx` gets intent `start` and calls `apiWrite('POST', '/api/v1/repositories/{id}/reviews')`. This is endpoint V1.
2. The `api` firewall restores the session. `TenantUserProvider::loadUserByIdentifier()` finds the user inside `TenantFilter::suspend()` and calls `TenantContext::set()`. `TenantContextListener::__invoke()` sets the same tenant at priority 7.
3. `#[MapEntity]` loads the `Repository` through the tenant filter, so an ID of another tenant gives 404. `ApiCsrfListener` checks the double-submit token. `#[IsGranted(User::ROLE)]` refuses an agent.
4. `ReviewController::start()` calls `RefName::fromString()` for a given ref, then `ReviewCommands::start()` with `CurrentActor::get()`, which gives `Actor::user()`.
5. `FetchRepository::readyClonePath()` throws `RepositoryUnavailable` when the sync state is `cloning`, `failed`, or `unavailable`.
6. When the ref is not a full SHA in the clone, `FetchRepository::fetchNow()` waits for the `fetch:<repositoryId>` lock in `RepositoryLock::acquire()` and calls `ServerClone::fetch()`, both within 60 s. After the lock is released, `ServerClone::synced()` dispatches `RepositoryFetched` for a successful fetch, so steps 6 to 11 of flow 4.3 follow.
7. A null ref becomes the default branch. `GitReader::resolveCommit()` gives the `CommitSha`. `PinRefs::pinReview()` writes the review pin before the transaction (D26).
8. `ReviewCommands::recordStarted()` calls `CommandExecutor::execute()`. `EventStoreSql::lockTenant()` runs `SELECT event_seq FROM tenant ... FOR UPDATE`. The decide closure reads `RepositoryRepository::findFresh()`, refuses removed access, and calls `ReviewDecider::decide()`, which returns `review.started`.
9. `EventStoreSql::append()` inserts the event. The `inTransaction` closure persists the new `Review`, because the repository is not event data. `ReviewProjector::project()` folds `ReviewReducer::apply()` into the row. The transaction commits.
10. `CommandExecutor` dispatches `EventsCommitted`. `PublishCommittedEvents::__invoke()` publishes an `events` update. If step 8 or 9 fails, `PinRefs::unpinReview()` removes the pin.
11. `ReviewCommands::start()` dispatches `IndexReview`. `TenantContextMiddleware::handle()` adds a `TenantStamp`. The controller answers `ReviewQuery::get()` with 201.
12. In the worker, `TenantContextMiddleware::handle()` sets the tenant from the stamp. `IndexReviewHandler::__invoke()` calls `GitReader::lsTreeRecursive()`, `IndexSql::insertFiles()`, and `BlobSymbols::parse()` per new PHP blob. `IndexSql::insertBlobs()` writes the parsed blobs in batches of about 1,000 rows, and before each update. It publishes an `index` update at the start, at most once per second during the run, and at the end.
13. Without **Carry over open requests from**, the browser action redirects to `/reviews/{id}`. With it, the action first calls V15, `CarryOverController::carryOver()`, which locates each source anchor with `Locator::locate()`, captures it with `AnchorFactory::capture()`, and writes the copies with one `CommandExecutor::execute()`. The form then shows `CarryOverPanel` with a link to the review. `reviewLoader()` reads V3, D1, and V11. `LiveSubscription.watch()` subscribes to the review topic. An `index` update for the open review revalidates the loaders when the router is idle. Updates during a revalidation cause one more.

### 4.2 An agent replies over MCP, and the browser updates live

An agent cannot open a request. A user opens one through `RequestController::open()`, `AnchorFactory::capture()` for a request with an anchor, and `RequestCommands::open()`. The agent side is the reply:

1. The agent sends `tools/call reply_to_request` to `POST /_mcp` with `Authorization: Bearer hr_...`.
2. The `mcp` firewall calls `ApiTokenHandler::getUserBadgeFrom()`. It finds the token by its SHA-256 inside `TenantFilter::suspend()`, requires `ApiTokenState::Active` and a live owner, calls `TenantContext::set()`, and calls `restrictToRepository()` for a scoped token. It returns a `UserBadge` that loads an `AgentUser`. `access_control` requires `ROLE_AGENT`.
3. `SessionLockedMcpController::handle()` holds the lock `mcp-session-<id>`, so one request per MCP session runs at a time. `TokenBoundSessionStore` binds the session to the token.
4. The SDK checks the arguments against `#[Schema]` and calls `McpTools::replyToRequest()`. `requestCommand()` runs the command through `McpTools::run()`, which maps each failure. `CurrentActor::get()` gives `Actor::agent(userId, agentName)`.
5. `RequestCommands::reply()` calls `reviewOf()`. The SQL filters apply to this lookup, so a request of another tenant or outside the token scope gives `NotFound`. Then `RequestCommands::run()` calls `CommandExecutor::execute()`.
6. Under the tenant row lock, the decide closure reads `ReviewRepository::findFresh()`, builds the state with `RequestStateMapper::toState()` from `ChangeRequestRepository::findFresh()` and `CommitLinkRepository::findFreshOf()`, and calls `RequestDecider::decide()`. The checks run in the order `invalid_input`, `review_closed`, `invalid_transition`, `commits_not_available`. The result is `request.commented`.
7. `CommandExecutor` numbers the event with the next `seq` and calls `EventStoreSql::append()`. `RequestProjector::project()` folds `RequestReducer::apply()`, writes the state with `RequestStateMapper::update()`, including `awaiting` from `AwaitingPolicy::evaluate()`, and persists a `Comment`. `ReviewProjector::project()` moves `Review.lastEventSeq`. The transaction commits.
8. `EventsCommitted` goes to `QueueLocationRecompute::onEventsCommitted()`, which does nothing, because no `request.opened` is in the batch. Then `PublishCommittedEvents::__invoke()` publishes `{type: events, reviewId, seq, requestIds}` on the review topic with the ID `urn:holistic-review:<tenantId>:<seq>`.
9. The tool returns `RequestQuery::commandResult()`. The shared normalizers give the same JSON as REST Q4. A `DomainError` becomes an `isError` result with the error body.
10. In the browser, `LiveSubscription.#receive()` gets the `events` update for the open review with a new `seq`. `#requestRevalidation()` records the `seq` and starts `#revalidate()`, which waits for `routerIdle()`. It skips the revalidation when `EventPositions` already covers every pending `seq`, which happens when the update reports a write of this browser.
11. `router.revalidate()` runs `requestLoader()` again, which reads Q3. `ThreadBox` in `RequestDetail.tsx` shows the reply. `TurnLabel` shows the new `awaiting` value.

### 4.3 A fetch moves the default branch

1. A trigger calls `FetchRepository::request()`: the Refresh button through `RepositoryController::fetch()` (R5), or a GitHub push through `GitHubRequestParser`, `GitHubWebhookConsumer::consume()`, a `GitHubPushJob` with a `TenantStamp`, and `GitHubPushHandler::__invoke()`.
2. `FetchRepository::request()` calls `RepositoryRepository::requestFetch()`, a conditional UPDATE. Only the winner dispatches `FetchRepositoryJob`, then `RepositoryChanged`. `PublishRepositoryChanges` publishes a `repository` update.
3. In the worker, `RepositorySyncHandler::handleFetch()` calls `clearFetchRequest()`, waits for the lock in `RepositoryLock::acquire()`, calls `startFetch()`, then `ServerClone::fetch()`.
4. `ServerClone::fetch()` builds `FetchCommand::gitHub()` or `FetchCommand::localDirectory()` and calls `BareClone::fetch()` with the SHAs of pending links. It reads `GitReader::headBranch()` and `resolveCommit()`, and calls `RepositoryRepository::recordSuccess()` with the new head.
5. `ServerClone::fetch()` returns `RepositoryFetched` with the previous head, from the entity read before the fetch, and the new head. The lock is released.
6. `ServerClone::synced()` dispatches `RepositoryFetched`, then `RepositoryChanged`.
7. `QueueLocationRecompute::onRepositoryFetched()` runs first, at priority 1. It returns when the two heads are equal. Otherwise it dispatches `RecomputeLocations` for each open review. This is step 2 of `specs/webhooks-and-live-updates.md` section 2.2. It runs before the link resolution of step 3, because a retried fetch finds an unchanged head, so a failing step 3 would drop the recompute.
8. `ResolvePendingCommitLinks::onRepositoryFetched()` pins each found commit with `PinRefs::pinCommit()` and writes `commit.found` per review through `CommandExecutor::execute()` with `Actor::system()`.
9. `RecomputeLocationsHandler::__invoke()` gets the head from `FetchRepository::cloneReady()->getDefaultBranchHead()`. For each request with an anchor it calls `Locator::locate()` with the `request.opened` event ID from `ReviewEventRepository::findOpenedEventIds()`.
10. `Locator::locate()` reads `LocationCacheEntryRepository::findOneByKey()`. A new head is a new key, so it calls `compute()` and stores the result with `LocationCacheSql::insert()`.
11. The handler sets `outdated` to true for the status `changed` or `orphaned`, else to false, with `ChangeRequest::recordOutdated()`. A failure keeps the old flag. No head gives null. When a flag changed, it flushes, then publishes `outdated` with the IDs of the changed requests only.
12. The browser gets `outdated` for the open review and revalidates. `OutdatedLabel` changes. `RequestQuery::get()` reads the location at the new head, from the cache that step 10 filled.

## 5. Cross-cutting rules

| Rule | How the code does it | Enforced by |
|---|---|---|
| Tenancy | `TenantSqlFilter` adds `tenant_id = :tenantId`, or `id` on `tenant`, to each ORM query on a tenant table and throws `MissingTenantContext` without a context. `RepositoryScopeSqlFilter` applies a token scope and throws for an entity without a rule. `TenantContext::reset()` runs after each worker request. | `tests/Functional/Tenancy/`, `Audit/CrossTenantCoverageTest.php`, `Api/CrossTenantRouteInventoryTest.php` |
| Context sources | Session: `TenantUserProvider` and `TenantContextListener`. Bearer: `ApiTokenHandler`. Messenger: `TenantContextMiddleware` stamps a dispatched message with the current tenant, runs a received one under its `TenantStamp`, and clears the entity manager after it in the worker. It refuses a message without a stamp, unless its class is in `app.tenantless_messages`. Console: a tenant argument or option. | `TenantContextResetTest`, `Messenger/MessengerTenantContextTest.php` |
| `TenantFilter::suspend()` | 9 calls in 8 files, in the four places of architecture section 4. Sign-in and token lookup: `TenantUserProvider`, `GitHubUserProvider`, `LocalUserProvisioner`, `ApiTokenHandler` (2 calls). Webhook router: `GitHubWebhookConsumer`. Projection rebuild: `RebuildProjectionsCommand`. Purge: `DeletionHandler`. Also `E2eSignInController`, in `e2e` only. | `TenantFilterSuspendTest` proves that the filter comes back. No test limits the call sites. |
| Raw SQL only in `*Sql` classes | `EventStoreSql`, `ReadSnapshotSql`, `DeletionSql`, `ViewedFileSql`, `IndexSql`, `LocationCacheSql`, `WebhookDeliverySql`. Each statement on a tenant table names its tenant column. The SQL filters do not see raw SQL. | `Audit/RawSqlTenantAuditTest.php` |
| Git only through `GitRunner` | `GitRunner` is the only class that creates a `Process`. `CatFileBatch` uses `GitRunner::start()`, and each `StreamedBlob` reads through a `CatFileBatch` of its own. Arguments are arrays with a fixed environment. Tokens reach git only through `GIT_CONFIG_*` variables and are redacted from errors (D38). Paths come from IDs through `TenantDataPaths`. | `Unit/Conventions/NoProcessOutsideGitTest.php`, which allows a `Process` only in `src/Git` |
| Locks | Event writes: the tenant row `FOR UPDATE`. `symfony/lock` keys on PostgreSQL advisory locks, all with no TTL: `fetch:<repositoryId>`, `github-repositories:<tenantId>`, `drafts:<reviewId>:<userId>`, `mcp-session-<id>`, `connect-local:<tenantId>:<directory>`, `api-token-issue:<userId>`. Deletes lock parent rows `FOR UPDATE`. Index and cache writes lock them `FOR KEY SHARE`. | `GaplessSequenceTest`, `Deletion/` tests |
| Dispatch outside transactions | `CommandExecutor::transactional()` and `FetchRepository::refuseOpenTransaction()` throw inside an open transaction. `EventsCommitted` follows the commit. `RepositoryFetched` follows the lock release. Pins are written before the transaction and removed on failure. | `PostCommitNotificationTest`, `StartReviewPinRollbackTest` |
| Fresh reads | Reads that decide something use `findFresh()` with `Query::HINT_REFRESH`. A plain `find()` can return an entity that the request loaded before another process committed. | `EventStore/FreshStateTest.php` |
| Live updates carry IDs only | `LivePublisher::publish()` runs after the flush or commit. The browser reads the data again through REST (D42). A failed publish is logged and not retried. | `Live/PublishAfterCommitTest.php`, `Live/HubIsolationTest.php` |
| Same JSON for REST and MCP | Both adapters call the same services and the same Serializer (D37). A response class is schema-shaped: its public properties are the fields of its schema, in schema order, and `ObjectNormalizer` writes it. A class cannot be schema-shaped when it wraps an entity, a recorded event, or an `App\Git` object, when it merges another schema, or when it computes a field. Such a class has one normalizer named after its schema, and that normalizer also maps its nested parts. Values go through the value normalizers, and an anchor goes through `AnchorCodec::encode()`, the stored payload format. The value normalizers are `ValueObjectNormalizer`, `ActorNormalizer`, `UtcTimeNormalizer`, and the framework enum normalizer. Every response validates against `docs/specs/schemas/`. | `Contract/` and `Mcp/` tests |

## 6. Tests

| Suite | Location | Tool and base | What it proves | Run |
|---|---|---|---|---|
| Unit | `tests/Unit/`, 65 classes | PHPUnit, `KernelTestCase` without boot | Value objects, the transition matrix, `awaiting` rules, event codecs, pure anchoring algorithms. `Conventions/` holds the meta-tests, for example domain purity, 800-line limit, no container mocks, TestDox on every test (D44). | `docker compose exec php bin/phpunit --testsuite unit` |
| Functional | `tests/Functional/`, 161 classes | `KernelTestCase` and `WebTestCase` on `app_test`, real git repositories in temporary directories, `MockHttpClient` for GitHub | Event store and projections, re-anchoring cases F1 to F14, tenant isolation, REST and MCP contracts against the schemas, webhooks, deletion, the headless slice | `make test` runs unit and functional |
| Front-end unit | `assets/**/*.test.ts`, 21 files | Vitest | Pure TypeScript: live subscription, helpers, enum arrays and fixtures against the schemas | `make front` |
| UI | `tests/ui/*.spec.ts`, 45 specs | Playwright Test on the host, against the SPA built by `tests/ui/vite.config.ts` | Screen flows with no PHP, database, or git. `tests/ui/support/mockApi.ts` answers `/api/v1`, and each mocked body validates against the schemas. A fake `EventSource` sends live updates. | `make ui` |
| Full-stack E2E | `tests/E2E/`, 7 classes | `FullStackTestCase` in `tests/Support/E2E/` with playwright-php, against the `e2e` site on port 8081 and `app_e2e` | Sign-in, the vertical slice through browser and MCP, a live agent reply, account deletion, a scoped token | `make e2e` |

Run `make db-test` once and after each migration. `make check` runs all gates. One PHP test: `docker compose exec php bin/phpunit --filter StartReviewTest`. One UI spec: `npx playwright test tests/ui/LineComment.spec.ts`. One Vitest file: `npx vitest run assets/live/useLiveRevalidation.test.ts`. Shared PHP fixtures and helpers are in `tests/Support/`.

## 7. Complexity ledger

"Forced by" means a spec, a decision, or a test requires the shape. "Candidate" means a smaller shape does the same job. "Done" means a step of the [simplification plan](plan/simplification.md) took the smaller shape: the Evidence cell names the step, what remains, and why. Size is the current size of the code.

| Area | Size | Verdict | Evidence, and a simpler shape |
|---|---|---|---|
| Event log, deciders, reducers, projectors | `Domain\Request`, `Domain\Event` with sub-namespaces, `EventStore`, `Projection`: about 3,400 lines | Forced by D3, D37, architecture section 6, and the domain purity rule of D44 | 21 event classes for 21 types, explicit codecs, and a transition test with 498 cases. The cost: one request exists as events, `RequestState`, `ChangeRequest`, and a view DTO. A new request field touches `RequestState`, `RequestReducer`, `ChangeRequest`, and `RequestStateMapper`. The purity rule covers all of `src/Domain` (S07, D44): it keeps the deciders, reducers, event codecs, and matching algorithms testable without the kernel, and the other Domain namespaces are pure at no cost. |
| `src/Mcp/BundledSchemas.php` | 3,269 lines | Forced by `specs/mcp.md` section 3 | `#[McpTool(outputSchema:)]` takes only constant expressions. `app:schema:bundle` generates the file, and `BundledSchemasUpToDateTest` checks it. |
| MCP tools next to REST actions | `Mcp\McpTools`: 15 tool methods, 495 lines | Done. The rest is forced by architecture section 7.1 and D20. | Done in S09. `Mcp\McpTools` holds the 15 tools, one `#[McpTool]` method each, and the error mapping. Each tool delegates to one query or one `RequestCommands` method. A command that returns its events goes through `requestCommand()`, and `link_commits` and `link_pull_request` go through `run()`, because they return `LinkCommitsResult` and `LinkPullRequestResult`. The bundle registers every `#[McpTool]` method of a service, and the method order is the `tools/list` order. Remains: `get_updates`, `list_requests`, and `list_reviews` also resolve the review or repository first, so `not_found` comes first, as in REST. |
| Normalizers and view DTOs | 16 normalizers with 1,105 lines, 33 DTO and enum files in `Application\Query` with 892 lines | Done. The rest is forced by D37 and architecture section 7.2. | Done in S08 with the rule of section 5. Schema-shaped classes are, for example, `RequestCommandResult`, `UpdatesPage`, `IndexStatus`, `LocationView`, and `CodeExcerpt`. Remains: the view classes that wrap an entity and add a few fields, because a schema-shaped copy repeats every field, and `RequestView` and `ThreadEntry`, which merge other schemas. |
| `Application\FetchRepository` | `FetchRepository`: 212 lines, 9 constructor parameters. `ServerClone`: 188 lines, 12 constructor parameters. | Done. The lock and sync rules are forced by D24 and D39. | Done in S05. `FetchRepository` queues clones and fetches, fetches within 60 s, removes access, and checks that a clone is ready. `ServerClone` clones and fetches from the source, with the GitHub token request and the local discovery. `RepositorySyncHandler` runs the clone and fetch jobs, `DeletionHandler` the clone deletion and the tenant purge, and `RepositoryLock::acquire()` waits for the lock for all of them. Both sources build one `Source\FetchCommand`. An interface with a picker in `App\Source` is not smaller, because only `ServerClone` picks. Remains: the 12 constructor parameters of `ServerClone`, which the two sources need. |
| Re-anchoring | 4 namespaces, about 1,300 lines | Done. Forced by architecture sections 5.4 and 5.5 and plan M28. | Done in S06. The pure algorithms are in `App\Domain\Matching`, and `App\Anchoring` holds the git and cache side. `Locator` is the only production caller of `Domain\Matching`, and the split lets the algorithms run in unit tests without git. The value types `ExactMatch`, `FuzzyMatch`, and `MappedRange` stay, because a fold gives an array shape or a second similarity run, and `Hunk` holds the boundary rules of `RangeMapper`. |
| Tenancy | `Tenancy`, 7 `*Sql` classes, `findFresh()` methods | Forced by D3, D17, architecture section 4, plan M14 | Each part closes a gap that the ORM filter leaves: DBAL queries, identity-map hits, scoped tokens. |
| Git layer | `App\Git`: 15 files, 1,193 lines | Done. The rest is forced by architecture section 4, D26, D38, D39. | Done in S03. `CatFileBatch` holds the one header parse, read loop, and 60 s timeout, and `StreamedBlob` reads through a `CatFileBatch` of its own. S01 deleted `GitReader::blobAt()`, which had no production caller. |
| Single-consumer services | `ReviewCommands`: 255 lines | Done for six services. `ReviewCommands` stays: inlining it is larger. | Done in S02. `ApiTokenIssuer`, `ConnectLocalRepository`, `CommitAvailability`, and `TreeQuery` live in their only consumers: `TokenController::create()`, `RepositoryController::connectLocal()`, `RequestQuery::target()`, and `CodeController::listTree()`. S01 deleted `EventLogQuery`, which had no production caller, and the tests read events through `tests/Support/EventStore/EventLog.php`. Done in F08: `InstallationTokenProvider` builds the App JWT, and `GitHubAppJwtTest` checks it through a token request with a mocked GitHub response. Remains: `ReviewCommands`, the one service with one consumer in `src`, `ReviewController`. Measured in F08: a prototype of the inlined controller has 263 lines against 341 for both classes, but only about 18 of the 78 lines come from the class boundary. The rest is compression that applies in place too. Without the service, the tests that record `review.started` without a clone (`CommandFixtures` with 22 call sites in 10 classes, `append-events.php`, `InsertAfterDeleteTest`, `DeletionRaceTest`, `LocationCacheTest`) and the tests that run the full V1 start (`IndexReviewHandlerTest`, `IndexLiveUpdateTest`, `RebuildIndexCommandTest`, `LocatorSymbolAndTargetCasesTest`, `BrowserFixtures`) need a 65-line copy of the write path in `tests/Support`, or a move of these `KernelTestCase` tests and fixtures to a signed-in HTTP client. With the copy, the change adds about 47 lines, and those tests run the copy, not the production write path. The other classes with one consumer hold a rule: the `*Sql` classes, `BareClone` and `StreamedBlob` in `App\Git`, the projectors, and the value object `Source\FetchCommand`. |
| Request commands with git work | `RequestCommands`: 294 lines, 10 constructor parameters | Done. The rest is forced by the `CommandExecutor` transaction rule and the pin refs of D26. | Done in S07. `RequestCommands::linkCommits()`, `unlinkCommit()`, and `markReadyForReview()` do the git work outside the event transaction, and the REST actions and MCP tools call them directly. Done in F08: D5, its only consumer, is `DraftController::submit()`, which uses the entity manager and `DraftRepository` of the controller. Remains: the 10 constructor parameters of `RequestCommands`: 4 for the git work (`FetchRepository`, `GitReader`, `PinRefs`, `TenantDataPaths`), `RequestQuery` for the Q7 and Q17 results, a logger for the unpin warning, and 4 that run the commands. |
| Message handlers | `App\MessageHandler`: 7 classes, 762 lines | Done | Done in S05. Every handler is in `App\MessageHandler`, and no message docblock names its handler. |
| Repeated helpers | `Http\Cursor`: 98 lines, and `Identifier::toRfc4122()` | Done | Done in S03. `Cursor::decode()` checks the sort key types and `Cursor::next()` makes `nextCursor` for `RequestQuery`, `ReviewQuery`, `RepositoryQuery`, and `CodeController`. Raw SQL, the SQL filter parameters, and list parameters convert a typed ID with `Identifier::toRfc4122()`. Remains: each list keeps its own `ORDER BY` and keyset condition, and `UpdatesQuery` pages by the event `seq` of `specs/README.md` section 5. |
| Typed IDs | 2 `Ulid::fromString()` calls in `src`, in `IdentifierType` and `Http\Cursor` | Done. Typed IDs are a choice of plan M06. | Done in S04. Entities store and return typed IDs, and repositories and queries bind them with the ID class as the parameter type. `config/packages/doctrine.yaml` registers `IdentifierType` once per ID class and `StringValueType` once per `CommitSha`, `BlobSha`, and `RepoPath`, with the class name as the type name, and each type reads its class from that name. Done in F08: `GitHubCandidate`, `GitHubInstallation`, and `ViewedFile` have ID classes, and `TypedIdMappingTest` finds no `ulid` column. Remains: the `ulid` type for the cursor keys, which `Http\Cursor` decodes to `Ulid`. |
| Small Domain items | 7 string value objects | Done | Done in S01: `Domain\Draft` and `Domain\User` are folded into `App\Entity`, and `ActorCodec`, the entity getters that nothing calls, and the `equals()` methods and `Domain\Matching` helpers that no production code calls are deleted. S04 folded the SHA and path types into `StringValueType`. S14 keeps one `SymbolRef::MAX_FQN_CHARACTERS` for `SymbolRef`, `Entity\Symbol`, and `PhpSymbolCollector`. Done in F08: the trait `Domain\StringValue` holds `toString()` and `__toString()` of the seven string value objects. `equals()` stays in its only users: `RepoPath` and `CommitSha` compare the string, and `RepositoryName` compares GitHub names without case. |
| Symbol index | `App\Index`: 11 files, 1,100 lines | Done. The rest is forced by architecture sections 4 and 7.1. | Done in S07. `BlobSymbols` and `DeclarationController` take `PhpSymbolExtractor` directly, with no interface. The `indexed_file` table is required by section 4, and `IndexSql::storedSymbolCounts()`, `IndexSql::declarations()`, and `IndexSql::search()` read it. S01 deleted `IndexedFileRepository`, which had no caller. |
| GitHub integration | `GitHub`, `Webhook`, `Application\InstallationSync`, `Application\GitHubRepositoriesLock`, and the three `MessageHandler\GitHub*Handler` classes: 15 files, about 1,300 lines | Done. The rest is forced by `specs/rest-api.md` R2 and `specs/webhooks-and-live-updates.md` section 1. | Done in S11. `RepositoryRepository::nameAfterRename()` holds the rename-collision rule, and the GitHub payloads go straight through `DenormalizerInterface::denormalize()`. S13 moved `InstallationSync` to `Application`. F06: `GitHubRepositoriesLock::run()` is the tenant lock of `InstallationSync`, `GitHubRepositoryHandler`, `GitHubInstallationHandler`, and `RepositoryController::connectGitHub()`, and runs their work only for an active tenant. |
| `CommitDiffController` | 119 lines | Done | Done in S10. A read that several callers share is one `Application\Query` method, and a read with one caller stays in its controller (plan README, "Code conventions"). Q9 has one caller, so `CommitDiffController` keeps it. R2 and D5 have one caller each and build their responses in `RepositoryCandidateController` and `DraftController::submit()`. `BlobStream`, the byte read of V8 and R7, is in `Application\Query`. `ReviewQuery::get()` has one caller and stays, because it builds `ReviewView` with the same queries as `list()`. The server sort stays, because it keeps the files that touch the anchor inside the 300-file cut. Q9 defines that order (D51), so `RequestChanges.tsx` shows the files in the order of Q9. |
| Front end | `assets/`: about 6,500 lines | Done. The rest is forced by D1, D29, architecture sections 8 and 12. | Done in S12. `assets/code/workerPool.ts` holds the pool options of both code view wrappers, and `REQUEST_PAGE_LIMIT`, `allRepositories()`, `SOURCE_NAMES`, and `dateTimeFormat` each have one definition. Remains: for a file view, `fileThreads()` in `fileData.ts` makes one Q1 call per 200 requests under the path prefix and one Q3 call per request on the file, because only Q3 returns a thread. With a filter, `requestListLoader()` reads D1 and one Q3 per reply draft whose request the filter hides, because only Q3 reads one request by ID. |
| Namespace cycles | 3 two-way pairs in 2 strongly connected sets | Done. Section 3.3 gives the reason of each pair that remains. | Done in S13. An exception lives in the namespace of its thrower. Section 3.3 names the three pairs that remain and the reason of each. |

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
| Test strategy, the decisions Dnn, risks, recorded deviations | [architecture.md](architecture.md) sections 10 to 13 |
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
