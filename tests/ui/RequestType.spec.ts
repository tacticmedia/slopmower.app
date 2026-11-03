import { commentBoxButton } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { expect, test } from './support/test';

const NEW_REQUEST = 'form[aria-label="New request"]';
const PENDING_DRAFTS = '[data-testid="pending-drafts"] [data-testid="draft"][data-draft-id]';
const DRAFTS = '/api/v1/reviews/:reviewId/drafts';

test.describe('RequestType', () => {
    test('The Type select goes into the D2 and D3 bodies', async ({ page, api }) => {
        const review = new FakeReview(api);
        await page.goto(`/reviews/${review.id}/requests`);

        await page.getByRole('button', { name: 'New request', exact: true }).click();
        await page.locator(NEW_REQUEST).getByRole('combobox', { name: 'Type' }).selectOption('rule');
        await page.locator(`${NEW_REQUEST} textarea`).fill('Never use float for money.');
        await commentBoxButton(page, 'Add comment').click();
        const draft = page.locator(PENDING_DRAFTS);
        await expect(draft).toContainText('Never use float for money.');

        await draft.getByRole('button', { name: 'Pending comment actions' }).click();
        await page.getByRole('menuitem', { name: 'Edit', exact: true }).click();
        const editor = page.locator('form[aria-label="Edit pending comment"]');
        await expect(editor.getByRole('combobox', { name: 'Type' })).toHaveValue('rule');
        await editor.getByRole('combobox', { name: 'Type' }).selectOption('change');
        await commentBoxButton(page, 'Update comment').click();
        await expect(editor).toHaveCount(0);

        expect(api.bodies('POST', DRAFTS)).toEqual([{ kind: 'new_request', anchor: null, body: 'Never use float for money.', requireApproval: true, type: 'rule' }]);
        expect(api.bodies('PATCH', '/api/v1/drafts/:draftId')).toEqual([{ body: 'Never use float for money.', requireApproval: true, type: 'change' }]);
    });

    test('Question hides Ask for a plan first and sends requireApproval false', async ({ page, api }) => {
        const review = new FakeReview(api);
        await page.goto(`/reviews/${review.id}/requests`);
        await page.getByRole('button', { name: 'New request', exact: true }).click();
        const askForPlan = page.locator(NEW_REQUEST).getByRole('checkbox', { name: 'Ask for a plan first', exact: true });
        await expect(askForPlan).toBeChecked();

        await page.locator(NEW_REQUEST).getByRole('combobox', { name: 'Type' }).selectOption('question');
        await expect(askForPlan).toHaveCount(0);
        await page.locator(`${NEW_REQUEST} textarea`).fill('Why float?');
        await commentBoxButton(page, 'Add comment').click();

        await expect.poll(() => api.bodies('POST', DRAFTS)).toEqual([{ kind: 'new_request', anchor: null, body: 'Why float?', requireApproval: false, type: 'question' }]);
    });

    test('A draft shows its Question label, a request row its Rule label, and a change no label', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.seedDraft({ kind: 'new_request', anchor: null, body: 'Why float?', type: 'question' });
        const rule = review.seedGeneral('Never use float for money.', false, null, 'rule');
        const change = review.seedGeneral('Wrap long lines.');

        await page.goto(`/reviews/${review.id}/requests`);

        await expect(page.locator(PENDING_DRAFTS).getByTestId('request-type')).toHaveText('Question');
        await expect(page.locator(`[data-testid="request-row"][data-request-id="${rule.id}"]`).getByTestId('request-type')).toHaveText('Rule');
        await expect(page.locator(`[data-testid="request-row"][data-request-id="${change.id}"]`)).toContainText('Wrap long lines.');
        await expect(page.locator(`[data-testid="request-row"][data-request-id="${change.id}"]`).getByTestId('request-type')).toHaveCount(0);
    });
});
