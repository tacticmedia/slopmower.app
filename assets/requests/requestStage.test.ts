import { describe, expect, it } from 'vitest';
import inProgress from '../../docs/specs/fixtures/request.in-progress.json';
import type { RequestStatus, ThreadEntryType } from '../api/types/common';
import type { Request as ReviewRequest } from '../api/types/request';
import type { ThreadEntry } from '../api/types/thread-entry';
import { planState, requestStage } from './requestStage';

const FIXTURE = inProgress as ReviewRequest;

/** The in-progress fixture of `claude-code laptop`, with its claim only while `in_progress`. */
function request(fields: Partial<Omit<ReviewRequest, 'status' | 'claimedBy' | 'claimedAt'>> & { status?: RequestStatus }): ReviewRequest {
    const status = fields.status ?? 'in_progress';
    const claim = 'in_progress' === status ? { claimedBy: FIXTURE.claimedBy, claimedAt: FIXTURE.claimedAt } : { claimedBy: null, claimedAt: null };

    return { ...FIXTURE, ...fields, status, ...claim } as ReviewRequest;
}

/** The fixture thread with one more agent entry. */
function withAgentEntry(type: ThreadEntryType, question: boolean): ThreadEntry[] {
    const last = FIXTURE.thread.at(-1) as ThreadEntry;

    return [...FIXTURE.thread, { ...last, seq: last.seq + 1, eventId: '01K6C2EVT00000000000001900', type, question, commitSha: null }];
}

const PENDING = { eventId: '01K6C2EVT00000000000001862', state: 'pending' } as const;

describe('RequestStageTest', () => {
    it('Rule 1: a pending suggestion waits for the decision', () => {
        expect(requestStage(request({ status: 'open', awaiting: 'user', suggestion: 'pending' }))).toBe('The agent suggested this change. Accept it so that an agent can work on it, or dismiss it.');
    });

    it('Rule 2: a verified request is resolved', () => {
        expect(requestStage(request({ status: 'verified', awaiting: 'none', closed: true }))).toBe('Resolved.');
    });

    it("Rule 3: a won't do of the user is closed", () => {
        expect(requestStage(request({ status: 'wont_do', awaiting: 'none', closed: true }))).toBe("Closed as won't do.");
    });

    it('Rule 4: a finished request with a user reply waits for the agent', () => {
        expect(requestStage(request({ status: 'ready_for_review', awaiting: 'agent' }))).toBe('Waiting for the agent to answer your reply.');
    });

    it('Rule 5: an agent question waits for a reply, also after a later pull request link', () => {
        const asked = withAgentEntry('request.commented', true);
        const linked = { ...(asked.at(-1) as ThreadEntry), seq: 1901, eventId: '01K6C2EVT00000000000001901', type: 'pull_request.linked', question: false };

        expect(requestStage(request({ awaiting: 'user', proposal: PENDING, thread: asked }))).toBe('The agent asked a question. Reply to continue.');
        expect(requestStage(request({ awaiting: 'user', proposal: PENDING, thread: [...asked, linked] }))).toBe('The agent asked a question. Reply to continue.');
    });

    it('Rule 6: a pending proposal waits for the decision', () => {
        expect(requestStage(request({ awaiting: 'user', proposal: PENDING, thread: withAgentEntry('request.proposed', false) }))).toBe('The agent proposed a plan. Approve it or request changes.');
    });

    it('Rule 7: a finished request waits for the check', () => {
        expect(requestStage(request({ status: 'ready_for_review', awaiting: 'user', requireApproval: true, proposal: { ...PENDING, state: 'approved' } }))).toBe(
            'The agent finished. Check the changes, then resolve the conversation or reopen it.',
        );
    });

    it("Rule 8: a won't do of the agent waits for the user", () => {
        expect(requestStage(request({ status: 'wont_do', awaiting: 'user' }))).toBe("The agent closed this as won't do. Resolve the conversation to agree, or reopen it.");
    });

    it('Rule 9: a rejected plan is revised', () => {
        expect(requestStage(request({ requireApproval: true, proposal: { ...PENDING, state: 'rejected' } }))).toBe('You requested changes to the plan. The agent revises it.');
    });

    it('Rule 10: a required plan comes before the code', () => {
        expect(requestStage(request({ requireApproval: true, proposal: null }))).toBe('The agent prepares a plan for your approval before it changes code.');
    });

    it('Rule 11: the claiming agent works', () => {
        expect(requestStage(request({ requireApproval: true, proposal: { ...PENDING, state: 'approved' } }))).toBe('claude-code laptop is working on this.');
    });

    it('Rule 12: an open request waits for a claim, with the plan when it is required', () => {
        expect(requestStage(request({ status: 'reopened', requireApproval: false }))).toBe('Waiting for an agent to claim this.');
        expect(requestStage(request({ status: 'open', requireApproval: true }))).toBe('Waiting for an agent to claim this. It proposes a plan first.');
    });

    it('After the work, the plan shows the proposal state or No plan, never the requirement', () => {
        expect(planState({ status: 'ready_for_review', requireApproval: true, proposal: { ...PENDING, state: 'approved' } })).toBe('Approved');
        expect(planState({ status: 'verified', requireApproval: true, proposal: null })).toBe('No plan');
        expect(planState({ status: 'in_progress', requireApproval: true, proposal: null })).toBe('Required before code changes');
    });
});
