import { describe, expect, it } from 'vitest';
import reviewOpen from '../../docs/specs/fixtures/review.open.json';
import { agentPrompt } from './promptSnippet';

describe('PromptSnippetTest', () => {
    it('The snippet names the review ID', () => {
        expect(agentPrompt(reviewOpen.id)).toBe(`Work on Holistic Review ${reviewOpen.id}`);
    });
});
