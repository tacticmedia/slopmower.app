import { describe, expect, it } from 'vitest';
import apiTokenWithSecret from '../../docs/specs/fixtures/api-token.with-secret.json';
import batchSubmissionSubmitted from '../../docs/specs/fixtures/batch-submission.submitted.json';
import blamePhp from '../../docs/specs/fixtures/blame.php.json';
import carryOverResultMixed from '../../docs/specs/fixtures/carry-over-result.mixed.json';
import commitDiffModified from '../../docs/specs/fixtures/commit-diff.modified.json';
import draftNewGeneralRequest from '../../docs/specs/fixtures/draft.new-general-request.json';
import draftNewRequest from '../../docs/specs/fixtures/draft.new-request.json';
import draftReply from '../../docs/specs/fixtures/draft.reply.json';
import errorCommitNotFound from '../../docs/specs/fixtures/error.commit-not-found.json';
import errorCommitsNotAvailable from '../../docs/specs/fixtures/error.commits-not-available.json';
import errorForbidden from '../../docs/specs/fixtures/error.forbidden.json';
import errorInternalError from '../../docs/specs/fixtures/error.internal-error.json';
import errorInvalidInputMethod from '../../docs/specs/fixtures/error.invalid-input-method.json';
import errorInvalidInput from '../../docs/specs/fixtures/error.invalid-input.json';
import errorInvalidTransition from '../../docs/specs/fixtures/error.invalid-transition.json';
import errorLimitExceeded from '../../docs/specs/fixtures/error.limit-exceeded.json';
import errorNotFound from '../../docs/specs/fixtures/error.not-found.json';
import errorRateLimited from '../../docs/specs/fixtures/error.rate-limited.json';
import errorRepositoryUnavailable from '../../docs/specs/fixtures/error.repository-unavailable.json';
import errorReviewClosed from '../../docs/specs/fixtures/error.review-closed.json';
import errorUnauthorized from '../../docs/specs/fixtures/error.unauthorized.json';
import fileContentPhp from '../../docs/specs/fixtures/file-content.php.json';
import inputsApproveProposal from '../../docs/specs/fixtures/inputs.approve-proposal.json';
import inputsCarryOver from '../../docs/specs/fixtures/inputs.carry-over.json';
import inputsCloseReview from '../../docs/specs/fixtures/inputs.close-review.json';
import inputsComment from '../../docs/specs/fixtures/inputs.comment.json';
import inputsConnectGithub from '../../docs/specs/fixtures/inputs.connect-github.json';
import inputsConnectLocal from '../../docs/specs/fixtures/inputs.connect-local.json';
import inputsCreateDraftGeneralRequest from '../../docs/specs/fixtures/inputs.create-draft-general-request.json';
import inputsCreateDraftNewRequest from '../../docs/specs/fixtures/inputs.create-draft-new-request.json';
import inputsCreateDraftReply from '../../docs/specs/fixtures/inputs.create-draft-reply.json';
import inputsCreateToken from '../../docs/specs/fixtures/inputs.create-token.json';
import inputsLinkCommits from '../../docs/specs/fixtures/inputs.link-commits.json';
import inputsLinkPullRequest from '../../docs/specs/fixtures/inputs.link-pull-request.json';
import inputsOpenGeneralRequest from '../../docs/specs/fixtures/inputs.open-general-request.json';
import inputsOpenRequestApproval from '../../docs/specs/fixtures/inputs.open-request-approval.json';
import inputsOpenRequest from '../../docs/specs/fixtures/inputs.open-request.json';
import inputsPlanRequirement from '../../docs/specs/fixtures/inputs.plan-requirement.json';
import inputsProposeSolution from '../../docs/specs/fixtures/inputs.propose-solution.json';
import inputsReady from '../../docs/specs/fixtures/inputs.ready.json';
import inputsRejectProposal from '../../docs/specs/fixtures/inputs.reject-proposal.json';
import inputsRelease from '../../docs/specs/fixtures/inputs.release.json';
import inputsStartReview from '../../docs/specs/fixtures/inputs.start-review.json';
import inputsSubmitDrafts from '../../docs/specs/fixtures/inputs.submit-drafts.json';
import inputsUnlinkPullRequest from '../../docs/specs/fixtures/inputs.unlink-pull-request.json';
import inputsUpdateDraft from '../../docs/specs/fixtures/inputs.update-draft.json';
import inputsViewedFile from '../../docs/specs/fixtures/inputs.viewed-file.json';
import inputsWontDo from '../../docs/specs/fixtures/inputs.wont-do.json';
import linkCommitsResultAvailable from '../../docs/specs/fixtures/link-commits-result.available.json';
import linkCommitsResultPending from '../../docs/specs/fixtures/link-commits-result.pending.json';
import linkPullRequestResultLinked from '../../docs/specs/fixtures/link-pull-request-result.linked.json';
import listsCandidateList from '../../docs/specs/fixtures/lists.candidate-list.json';
import listsDeclarationList from '../../docs/specs/fixtures/lists.declaration-list.json';
import listsDraftList from '../../docs/specs/fixtures/lists.draft-list.json';
import listsMercureAuthorization from '../../docs/specs/fixtures/lists.mercure-authorization.json';
import listsRepositoryPage from '../../docs/specs/fixtures/lists.repository-page.json';
import listsRequestPage from '../../docs/specs/fixtures/lists.request-page.json';
import listsReviewPage from '../../docs/specs/fixtures/lists.review-page.json';
import listsSearchHitList from '../../docs/specs/fixtures/lists.search-hit-list.json';
import listsSymbolList from '../../docs/specs/fixtures/lists.symbol-list.json';
import listsTokenList from '../../docs/specs/fixtures/lists.token-list.json';
import listsTreeListing from '../../docs/specs/fixtures/lists.tree-listing.json';
import listsViewedFiles from '../../docs/specs/fixtures/lists.viewed-files.json';
import liveUpdateCandidatesChanged from '../../docs/specs/fixtures/live-update.candidates-changed.json';
import liveUpdateEventsBatch from '../../docs/specs/fixtures/live-update.events-batch.json';
import liveUpdateEvents from '../../docs/specs/fixtures/live-update.events.json';
import liveUpdateIndex from '../../docs/specs/fixtures/live-update.index.json';
import liveUpdateOutdated from '../../docs/specs/fixtures/live-update.outdated.json';
import liveUpdatePullRequest from '../../docs/specs/fixtures/live-update.pull-request.json';
import liveUpdateRepositoryDeleted from '../../docs/specs/fixtures/live-update.repository-deleted.json';
import liveUpdateRepository from '../../docs/specs/fixtures/live-update.repository.json';
import meAgent from '../../docs/specs/fixtures/me.agent.json';
import meLocalUser from '../../docs/specs/fixtures/me.local-user.json';
import repositoryGithubCloning from '../../docs/specs/fixtures/repository.github-cloning.json';
import repositoryLocalIdle from '../../docs/specs/fixtures/repository.local-idle.json';
import requestCommandResultClaim from '../../docs/specs/fixtures/request-command-result.claim.json';
import requestCommandResultProposed from '../../docs/specs/fixtures/request-command-result.proposed.json';
import requestCommandResultQuestion from '../../docs/specs/fixtures/request-command-result.question.json';
import requestCommandResultReady from '../../docs/specs/fixtures/request-command-result.ready.json';
import requestCommandResultRelease from '../../docs/specs/fixtures/request-command-result.release.json';
import requestCarried from '../../docs/specs/fixtures/request.carried.json';
import requestGeneral from '../../docs/specs/fixtures/request.general.json';
import requestInProgress from '../../docs/specs/fixtures/request.in-progress.json';
import requestOpenedRange from '../../docs/specs/fixtures/request.opened-range.json';
import requestProposalPending from '../../docs/specs/fixtures/request.proposal-pending.json';
import requestPullRequest from '../../docs/specs/fixtures/request.pull-request.json';
import requestWithExcerpt from '../../docs/specs/fixtures/request.with-excerpt.json';
import reviewClosed from '../../docs/specs/fixtures/review.closed.json';
import reviewIndexStatus from '../../docs/specs/fixtures/review.index-status.json';
import reviewOpen from '../../docs/specs/fixtures/review.open.json';
import updatesPageCursor from '../../docs/specs/fixtures/updates-page.cursor.json';
import updatesPageFirstCall from '../../docs/specs/fixtures/updates-page.first-call.json';
import type { ApiTokenWithSecret } from './types/api-token';
import type { BatchSubmission } from './types/batch-submission';
import type { Blame } from './types/blame';
import type { CarryOverResult } from './types/carry-over-result';
import type { CommitDiff } from './types/commit-diff';
import type { Draft } from './types/draft';
import type { ErrorBody } from './types/error';
import type { FileContent } from './types/file-content';
import type {
    ApproveProposalInput,
    CarryOverInput,
    CloseReviewInput,
    CommentInput,
    ConnectRepositoryInput,
    CreateDraftInput,
    CreateTokenInput,
    LinkCommitsInput,
    LinkPullRequestInput,
    OpenRequestInput,
    OptionalNoteInput,
    PlanRequirementInput,
    ProposeSolutionInput,
    RejectProposalInput,
    RequiredNoteInput,
    StartReviewInput,
    SubmitDraftsInput,
    UnlinkPullRequestInput,
    UpdateDraftInput,
    ViewedFileInput,
    WontDoInput,
} from './types/inputs';
import type { LinkCommitsResult } from './types/link-commits-result';
import type { LinkPullRequestResult } from './types/link-pull-request-result';
import type {
    CandidateList,
    DeclarationList,
    DraftList,
    MercureAuthorization,
    RepositoryPage,
    RequestPage,
    ReviewPage,
    SearchHitList,
    SymbolList,
    TokenList,
    TreeListing,
    ViewedFiles,
} from './types/lists';
import type { LiveUpdate } from './types/live-update';
import type { Me } from './types/me';
import type { Repository } from './types/repository';
import type { Request } from './types/request';
import type { RequestCommandResult } from './types/request-command-result';
import type { IndexStatus, Review } from './types/review';
import type { UpdatesPage } from './types/updates-page';

/** TypeScript types a JSON import with `string` where the DTO has a string-literal union. */
type Widen<T> = T extends string
    ? string
    : T extends readonly (infer Item)[]
      ? Widen<Item>[]
      : T extends object
        ? { [K in keyof T]: Widen<T[K]> }
        : T;

/** A widened `type` matches every member, so each update is checked against its own member. */
type WidenedLiveUpdate<Type extends LiveUpdate['type']> = Widen<Extract<LiveUpdate, { type: Type }>>;

const checkedFixtures = {
    'api-token.with-secret.json': apiTokenWithSecret satisfies Widen<ApiTokenWithSecret>,
    'batch-submission.submitted.json': batchSubmissionSubmitted satisfies Widen<BatchSubmission>,
    'blame.php.json': blamePhp satisfies Widen<Blame>,
    'carry-over-result.mixed.json': carryOverResultMixed satisfies Widen<CarryOverResult>,
    'commit-diff.modified.json': commitDiffModified satisfies Widen<CommitDiff>,
    'draft.new-general-request.json': draftNewGeneralRequest satisfies Widen<Draft>,
    'draft.new-request.json': draftNewRequest satisfies Widen<Draft>,
    'draft.reply.json': draftReply satisfies Widen<Draft>,
    'error.commit-not-found.json': errorCommitNotFound satisfies Widen<ErrorBody>,
    'error.commits-not-available.json': errorCommitsNotAvailable satisfies Widen<ErrorBody>,
    'error.forbidden.json': errorForbidden satisfies Widen<ErrorBody>,
    'error.internal-error.json': errorInternalError satisfies Widen<ErrorBody>,
    'error.invalid-input-method.json': errorInvalidInputMethod satisfies Widen<ErrorBody>,
    'error.invalid-input.json': errorInvalidInput satisfies Widen<ErrorBody>,
    'error.invalid-transition.json': errorInvalidTransition satisfies Widen<ErrorBody>,
    'error.limit-exceeded.json': errorLimitExceeded satisfies Widen<ErrorBody>,
    'error.not-found.json': errorNotFound satisfies Widen<ErrorBody>,
    'error.rate-limited.json': errorRateLimited satisfies Widen<ErrorBody>,
    'error.repository-unavailable.json': errorRepositoryUnavailable satisfies Widen<ErrorBody>,
    'error.review-closed.json': errorReviewClosed satisfies Widen<ErrorBody>,
    'error.unauthorized.json': errorUnauthorized satisfies Widen<ErrorBody>,
    'file-content.php.json': fileContentPhp satisfies Widen<FileContent>,
    'inputs.approve-proposal.json': inputsApproveProposal satisfies Widen<ApproveProposalInput>,
    'inputs.carry-over.json': inputsCarryOver satisfies Widen<CarryOverInput>,
    'inputs.close-review.json': inputsCloseReview satisfies Widen<CloseReviewInput>,
    'inputs.comment.json': inputsComment satisfies Widen<CommentInput>,
    'inputs.connect-github.json': inputsConnectGithub satisfies Widen<ConnectRepositoryInput>,
    'inputs.connect-local.json': inputsConnectLocal satisfies Widen<ConnectRepositoryInput>,
    'inputs.create-draft-general-request.json': inputsCreateDraftGeneralRequest satisfies Widen<CreateDraftInput>,
    'inputs.create-draft-new-request.json': inputsCreateDraftNewRequest satisfies Widen<CreateDraftInput>,
    'inputs.create-draft-reply.json': inputsCreateDraftReply satisfies Widen<CreateDraftInput>,
    'inputs.create-token.json': inputsCreateToken satisfies Widen<CreateTokenInput>,
    'inputs.link-commits.json': inputsLinkCommits satisfies Widen<LinkCommitsInput>,
    'inputs.link-pull-request.json': inputsLinkPullRequest satisfies Widen<LinkPullRequestInput>,
    'inputs.open-general-request.json': inputsOpenGeneralRequest satisfies Widen<OpenRequestInput>,
    'inputs.open-request-approval.json': inputsOpenRequestApproval satisfies Widen<OpenRequestInput>,
    'inputs.open-request.json': inputsOpenRequest satisfies Widen<OpenRequestInput>,
    'inputs.plan-requirement.json': inputsPlanRequirement satisfies Widen<PlanRequirementInput>,
    'inputs.propose-solution.json': inputsProposeSolution satisfies Widen<ProposeSolutionInput>,
    'inputs.ready.json': inputsReady satisfies Widen<RequiredNoteInput>,
    'inputs.reject-proposal.json': inputsRejectProposal satisfies Widen<RejectProposalInput>,
    'inputs.release.json': inputsRelease satisfies Widen<OptionalNoteInput>,
    'inputs.start-review.json': inputsStartReview satisfies Widen<StartReviewInput>,
    'inputs.submit-drafts.json': inputsSubmitDrafts satisfies Widen<SubmitDraftsInput>,
    'inputs.unlink-pull-request.json': inputsUnlinkPullRequest satisfies Widen<UnlinkPullRequestInput>,
    'inputs.update-draft.json': inputsUpdateDraft satisfies Widen<UpdateDraftInput>,
    'inputs.viewed-file.json': inputsViewedFile satisfies Widen<ViewedFileInput>,
    'inputs.wont-do.json': inputsWontDo satisfies Widen<WontDoInput>,
    'link-commits-result.available.json': linkCommitsResultAvailable satisfies Widen<LinkCommitsResult>,
    'link-commits-result.pending.json': linkCommitsResultPending satisfies Widen<LinkCommitsResult>,
    'link-pull-request-result.linked.json': linkPullRequestResultLinked satisfies Widen<LinkPullRequestResult>,
    'lists.candidate-list.json': listsCandidateList satisfies Widen<CandidateList>,
    'lists.declaration-list.json': listsDeclarationList satisfies Widen<DeclarationList>,
    'lists.draft-list.json': listsDraftList satisfies Widen<DraftList>,
    'lists.mercure-authorization.json': listsMercureAuthorization satisfies Widen<MercureAuthorization>,
    'lists.repository-page.json': listsRepositoryPage satisfies Widen<RepositoryPage>,
    'lists.request-page.json': listsRequestPage satisfies Widen<RequestPage>,
    'lists.review-page.json': listsReviewPage satisfies Widen<ReviewPage>,
    'lists.search-hit-list.json': listsSearchHitList satisfies Widen<SearchHitList>,
    'lists.symbol-list.json': listsSymbolList satisfies Widen<SymbolList>,
    'lists.token-list.json': listsTokenList satisfies Widen<TokenList>,
    'lists.tree-listing.json': listsTreeListing satisfies Widen<TreeListing>,
    'lists.viewed-files.json': listsViewedFiles satisfies Widen<ViewedFiles>,
    'live-update.candidates-changed.json': liveUpdateCandidatesChanged satisfies WidenedLiveUpdate<'candidates_changed'>,
    'live-update.events-batch.json': liveUpdateEventsBatch satisfies WidenedLiveUpdate<'events'>,
    'live-update.events.json': liveUpdateEvents satisfies WidenedLiveUpdate<'events'>,
    'live-update.index.json': liveUpdateIndex satisfies WidenedLiveUpdate<'index'>,
    'live-update.outdated.json': liveUpdateOutdated satisfies WidenedLiveUpdate<'outdated'>,
    'live-update.pull-request.json': liveUpdatePullRequest satisfies WidenedLiveUpdate<'pull_request'>,
    'live-update.repository-deleted.json': liveUpdateRepositoryDeleted satisfies WidenedLiveUpdate<'repository_deleted'>,
    'live-update.repository.json': liveUpdateRepository satisfies WidenedLiveUpdate<'repository'>,
    'me.agent.json': meAgent satisfies Widen<Me>,
    'me.local-user.json': meLocalUser satisfies Widen<Me>,
    'repository.github-cloning.json': repositoryGithubCloning satisfies Widen<Repository>,
    'repository.local-idle.json': repositoryLocalIdle satisfies Widen<Repository>,
    'request-command-result.claim.json': requestCommandResultClaim satisfies Widen<RequestCommandResult>,
    'request-command-result.proposed.json': requestCommandResultProposed satisfies Widen<RequestCommandResult>,
    'request-command-result.question.json': requestCommandResultQuestion satisfies Widen<RequestCommandResult>,
    'request-command-result.ready.json': requestCommandResultReady satisfies Widen<RequestCommandResult>,
    'request-command-result.release.json': requestCommandResultRelease satisfies Widen<RequestCommandResult>,
    'request.carried.json': requestCarried satisfies Widen<Request>,
    'request.general.json': requestGeneral satisfies Widen<Request>,
    'request.in-progress.json': requestInProgress satisfies Widen<Request>,
    'request.opened-range.json': requestOpenedRange satisfies Widen<Request>,
    'request.proposal-pending.json': requestProposalPending satisfies Widen<Request>,
    'request.pull-request.json': requestPullRequest satisfies Widen<Request>,
    'request.with-excerpt.json': requestWithExcerpt satisfies Widen<Request>,
    'review.closed.json': reviewClosed satisfies Widen<Review>,
    'review.index-status.json': reviewIndexStatus satisfies Widen<IndexStatus>,
    'review.open.json': reviewOpen satisfies Widen<Review>,
    'updates-page.cursor.json': updatesPageCursor satisfies Widen<UpdatesPage>,
    'updates-page.first-call.json': updatesPageFirstCall satisfies Widen<UpdatesPage>,
};

describe('ContractFixturesTypeTest', () => {
    it('Each REST fixture satisfies its widened DTO type', () => {
        const fixtureFileNames = Object.keys(import.meta.glob('../../docs/specs/fixtures/*.json')).map((path) =>
            path.slice(path.lastIndexOf('/') + 1),
        );

        expect(Object.keys(checkedFixtures).toSorted()).toEqual(fixtureFileNames.toSorted());
    });
});
