import { describe, expect, it } from 'vitest';
import proposalPending from '../../docs/specs/fixtures/request.proposal-pending.json';
import type { Proposal, ThreadEntryType } from '../api/types/common';
import type { Request as ReviewRequest } from '../api/types/request';
import type { ThreadEntry } from '../api/types/thread-entry';
import { threadView } from './threadView';

const FIXTURE = proposalPending as ReviewRequest;

/** The fixture's opened, claimed, and proposed entries are the first three. */
function request(types: readonly ThreadEntryType[], proposal: Proposal | null): ReviewRequest {
    const [opened, claimed, proposed] = FIXTURE.thread as [ThreadEntry, ThreadEntry, ThreadEntry];
    const later = types.map((type, index): ThreadEntry => ({ ...proposed, seq: proposed.seq + index + 1, eventId: `01K6C2EVT0000000000000190${index}`, type }));

    return { ...FIXTURE, proposal, thread: [opened, claimed, proposed, ...later] };
}

function proposalStates(subject: ReviewRequest): string[] {
    return threadView(subject).comments.flatMap(({ proposal }) => (null === proposal ? [] : [proposal.state]));
}

describe('ThreadViewTest', () => {
    it('The current proposal shows its own state', () => {
        expect(proposalStates(FIXTURE)).toEqual(['pending']);
        expect(threadView(FIXTURE).proposalPending).toBe(true);
    });

    it('An approved proposal of a reopened request stays approved', () => {
        const reopened = request(['request.proposal_approved', 'request.ready_for_review', 'request.reopened'], null);

        expect(proposalStates(reopened)).toEqual(['approved']);
        expect(threadView(reopened).proposalPending).toBe(false);
    });

    it('A rejected proposal stays rejected after a new proposal', () => {
        const repeated = request(['request.proposal_rejected', 'request.proposed'], { eventId: '01K6C2EVT00000000000001901', state: 'pending' });

        expect(proposalStates(repeated)).toEqual(['rejected', 'pending']);
    });

    it('An undecided proposal that a new one replaced is superseded', () => {
        expect(proposalStates(request(['request.proposed'], { eventId: '01K6C2EVT00000000000001900', state: 'pending' }))).toEqual(['superseded', 'pending']);
    });

    it('The last proposal of a released claim is withdrawn', () => {
        expect(proposalStates(request(['request.released'], null))).toEqual(['withdrawn']);
    });
});
