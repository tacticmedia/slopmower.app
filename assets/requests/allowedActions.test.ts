import { describe, expect, it } from 'vitest';
import { BUTTON_LABELS, requestButtons } from './allowedActions';

describe('AllowedActionsMappingTest', () => {
    it('Maps each allowed action to its button and ignores unknown actions', () => {
        const labels = (actions: string[]): string[] => requestButtons(actions).map((button) => BUTTON_LABELS[button]);

        expect(labels(['release', 'reply', 'mark_wont_do', 'claim', 'link_commits', 'reopen', 'escalate', 'verify'])).toEqual([
            'Resolve conversation',
            'Reopen',
            "Close as won't do",
            'Unassign',
        ]);
        expect(labels(['reply', 'reopen'])).toEqual(['Reopen']);
        expect(labels([])).toEqual([]);
    });
});
