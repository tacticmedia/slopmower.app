import { describe, expect, it } from 'vitest';
import { splitIssueKeys } from './issueKeys';

const PROJECT_KEYS = ['BILL', 'SM'];

describe('IssueKeysTest', () => {
    it('A key of a known project is a part with the screen of its issue', () => {
        expect(splitIssueKeys('SM-12 follows (BILL-9).', PROJECT_KEYS)).toEqual([
            { text: 'SM-12', issueHref: '/issues/SM-12' },
            { text: ' follows (', issueHref: null },
            { text: 'BILL-9', issueHref: '/issues/BILL-9' },
            { text: ').', issueHref: null },
        ]);
    });

    it('A key of an unknown project stays text', () => {
        expect(splitIssueKeys('UTF-8 and SHA-256', PROJECT_KEYS)).toEqual([{ text: 'UTF-8 and SHA-256', issueHref: null }]);
    });

    it('A number with a leading zero is no key', () => {
        expect(splitIssueKeys('SM-012', PROJECT_KEYS)).toEqual([{ text: 'SM-012', issueHref: null }]);
    });

    it('A key inside a longer word is no key', () => {
        expect(splitIssueKeys('XSM-12 xSM-12 _SM-12 SM-12a SM-1234567890', PROJECT_KEYS)).toEqual([{ text: 'XSM-12 xSM-12 _SM-12 SM-12a SM-1234567890', issueHref: null }]);
    });
});
