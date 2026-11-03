import type { Question } from '../../assets/api/types/common';
import { FakeReview } from './support/fakeReview';
import { DETAIL_THREAD, openRequest } from './support/requestScreens';
import { expect, test } from './support/test';

const FORM = `${DETAIL_THREAD} [data-testid="open-questions"]`;

const QUESTIONS: Question[] = [
    { question: 'Cover the shipping totals too?', detail: 'Shipping uses its own **rounding** today.', answer: null },
    { question: 'Which type holds the amounts?', detail: null, answer: null },
];

test.describe('AgentQuestions', () => {
    test('Open questions show above the reply box, and Send answers sends every question with its answer', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedGeneral('Use Money instead of float for totals.');
        review.agentAsks(request.id, 'Two questions before I start.', QUESTIONS);
        await openRequest(page, request.id);

        const form = page.locator(FORM);
        await expect(form.locator('strong')).toHaveText('rounding');
        await expect(page.locator(`${DETAIL_THREAD} [data-testid="comment-questions"]`), 'The open questions show only in the form.').toHaveCount(0);
        const formBox = await form.boundingBox();
        const replyBox = await page.locator(`${DETAIL_THREAD} [data-testid="comment-box"]`).boundingBox();
        expect(formBox?.y ?? Infinity).toBeLessThan(replyBox?.y ?? 0);

        const send = form.getByRole('button', { name: 'Send answers', exact: true });
        await form.getByRole('group', { name: QUESTIONS[0]?.question }).getByRole('button', { name: 'Yes', exact: true }).click();
        await expect(send, 'Every question needs an answer.').toBeDisabled();
        await form.getByRole('group', { name: QUESTIONS[1]?.question }).getByRole('textbox', { name: 'Other answer' }).fill('Money');
        await send.click();

        await expect(form).toHaveCount(0);
        expect(api.bodies('POST', '/api/v1/requests/:requestId/comments')).toEqual([
            {
                body: '',
                questions: [
                    { question: QUESTIONS[0]?.question, answer: 'Yes' },
                    { question: QUESTIONS[1]?.question, answer: 'Money' },
                ],
            },
        ]);
        const answers = page.locator(`${DETAIL_THREAD} [data-testid="thread-comment"]`).last().locator('[data-testid="answer"]');
        await expect(answers).toHaveText(['Yes', 'Money']);
        await expect(page.locator(`${DETAIL_THREAD} [data-testid="comment-questions"]`)).toHaveCount(2);
    });
});
