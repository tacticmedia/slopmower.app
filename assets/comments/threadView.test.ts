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

/** A `request.commented` entry with questions, after the fixture's entries. */
function withComments(...comments: { kind: 'agent' | 'user'; answer: string | null }[]): ReviewRequest {
    const [opened, , proposed] = FIXTURE.thread as [ThreadEntry, ThreadEntry, ThreadEntry];
    const later = comments.map(({ kind, answer }, index): ThreadEntry => ({
        ...proposed,
        seq: proposed.seq + index + 1,
        eventId: `01K6C2EVT0000000000000190${index}`,
        type: 'request.commented',
        actor: 'agent' === kind ? proposed.actor : opened.actor,
        body: '',
        questions: [{ question: 'Cover the shipping totals?', detail: null, answer }],
    }));

    return { ...FIXTURE, thread: [...FIXTURE.thread, ...later] };
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

    it('The questions of the latest agent comment are open until a user comment answers them', () => {
        expect(threadView(withComments({ kind: 'agent', answer: null })).openQuestions?.key).toBe('01K6C2EVT00000000000001900');
        expect(threadView(withComments({ kind: 'agent', answer: null }, { kind: 'user', answer: 'Yes' })).openQuestions).toBeNull();
        expect(threadView(withComments({ kind: 'agent', answer: null }, { kind: 'user', answer: 'Yes' }, { kind: 'agent', answer: null })).openQuestions?.key).toBe(
            '01K6C2EVT00000000000001902',
        );
    });

    it('An answer comment without text has no body', () => {
        const answered = threadView(withComments({ kind: 'user', answer: 'Yes' }));

        expect(answered.comments.at(-1)?.body).toBeNull();
        expect(answered.comments.at(-1)?.questions).toHaveLength(1);
    });
});
