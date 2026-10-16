import type { ProposalState, ThreadEntryType } from '../api/types/common';
import type { Request as ReviewRequest } from '../api/types/request';
import type { ThreadEntry } from '../api/types/thread-entry';

/** Agent entries that are no agent event for the `awaiting` rules of architecture section 6. */
const NO_AGENT_EVENT: readonly ThreadEntryType[] = ['pull_request.linked', 'pull_request.unlinked', 'request.retitled'];

/** The next-step line of the request detail. The first matching rule wins. */
export function requestStage(request: ReviewRequest): string {
    const { awaiting, requireApproval, proposal } = request;

    if ('pending' === request.suggestion) {
        return 'The agent suggested this change. Accept it so that an agent can work on it, or dismiss it.';
    }
    if ('verified' === request.status) {
        return 'Resolved.';
    }
    if ('wont_do' === request.status && request.closed) {
        return "Closed as won't do.";
    }
    if (('ready_for_review' === request.status || 'wont_do' === request.status) && 'agent' === awaiting) {
        return 'Waiting for the agent to answer your reply.';
    }
    if ('user' === awaiting && agentAsked(request.thread)) {
        return 'The agent asked a question. Reply to continue.';
    }
    if ('pending' === proposal?.state) {
        return 'The agent proposed a plan. Approve it or request changes.';
    }

    switch (request.status) {
        case 'ready_for_review':
            return 'The agent finished. Check the changes, then resolve the conversation or reopen it.';
        case 'wont_do':
            return "The agent closed this as won't do. Resolve the conversation to agree, or reopen it.";
        case 'in_progress':
            if (requireApproval && 'rejected' === proposal?.state) {
                return 'You requested changes to the plan. The agent revises it.';
            }
            if (requireApproval && null === proposal) {
                return 'The agent prepares a plan for your approval before it changes code.';
            }

            return `${request.claimedBy.name} is working on this.`;
        case 'open':
        case 'reopened':
            return requireApproval ? 'Waiting for an agent to claim this. It proposes a plan first.' : 'Waiting for an agent to claim this.';
    }
}

function agentAsked(thread: readonly ThreadEntry[]): boolean {
    const last = thread.findLast(({ actor, type }) => 'agent' === actor.kind && !NO_AGENT_EVENT.includes(type));

    return 'request.commented' === last?.type && last.question;
}

const PROPOSAL_TEXT: Record<ProposalState, string> = {
    pending: 'Waiting for your approval',
    approved: 'Approved',
    rejected: 'Changes requested',
};

/**
 * The Plan value of the sidebar. After the work, `requireApproval` no longer counts.
 */
export function planState({ status, requireApproval, proposal }: Pick<ReviewRequest, 'status' | 'requireApproval' | 'proposal'>): string {
    if (null !== proposal) {
        return PROPOSAL_TEXT[proposal.state];
    }
    if ('ready_for_review' === status || 'verified' === status || 'wont_do' === status) {
        return 'No plan';
    }

    return requireApproval ? 'Required before code changes' : 'Not required';
}
