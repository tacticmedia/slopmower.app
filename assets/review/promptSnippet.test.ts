import { describe, expect, it } from 'vitest';
import reviewOpen from '../../docs/specs/fixtures/review.open.json';
import { agentPrompt, suggestionPrompt } from './promptSnippet';

describe('PromptSnippetTest', () => {
    it('The snippet names the review ID', () => {
        expect(agentPrompt(reviewOpen.id)).toBe(`Work on Slopmower review ${reviewOpen.id}`);
    });

    it('The suggestion prompt names the review ID', () => {
        expect(suggestionPrompt(reviewOpen.id)).toBe(`Review the code of Slopmower review ${reviewOpen.id} and suggest changes`);
    });
});
