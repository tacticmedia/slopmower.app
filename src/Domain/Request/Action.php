<?php

declare(strict_types=1);

namespace App\Domain\Request;

enum Action: string
{
    case Reply = 'reply';
    case Claim = 'claim';
    case Release = 'release';
    case LinkCommits = 'link_commits';
    case UnlinkCommit = 'unlink_commit';
    case LinkPullRequest = 'link_pull_request';
    case UnlinkPullRequest = 'unlink_pull_request';
    case MarkReadyForReview = 'mark_ready_for_review';
    case MarkWontDo = 'mark_wont_do';
    case Verify = 'verify';
    case Reopen = 'reopen';
    case ProposeSolution = 'propose_solution';
    case ApproveProposal = 'approve_proposal';
    case RejectProposal = 'reject_proposal';
    case SetPlanRequirement = 'set_plan_requirement';
    case AcceptSuggestion = 'accept_suggestion';
    case DismissSuggestion = 'dismiss_suggestion';
}
