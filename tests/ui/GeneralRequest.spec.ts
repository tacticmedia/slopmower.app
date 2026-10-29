import type { Page } from '@playwright/test';
import { fixture } from './support/builders';
import { commentBoxButton, SUBMIT_FEEDBACK, submitFeedback } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { REQUEST_ROWS } from './support/requestScreens';
import { expect, test } from './support/test';

const NEW_REQUEST_BOX = 'form[aria-label="New request"] textarea';
const PENDING_DRAFTS = '[data-testid="pending-drafts"]';

type TitledText = { title: string; body: string };

const DRAFT = fixture<TitledText>('inputs.create-draft-general-request.json');

async function writeNewRequest(page: Page, review: FakeReview, body: string, title: string | null = null): Promise<void> {
    await page.goto(`/reviews/${review.id}/requests`);
    await page.getByRole('button', { name: 'New request', exact: true }).click();
    if (null !== title) {
        await page.locator('form[aria-label="New request"]').getByRole('textbox', { name: 'Title', exact: true }).fill(title);
    }
    await page.locator(NEW_REQUEST_BOX).fill(body);
}

test.describe('GeneralRequest', () => {
    test('New request adds a general draft with its title, and Submit feedback opens it as General', async ({ page, api }) => {
        const review = new FakeReview(api);

        await writeNewRequest(page, review, DRAFT.body, DRAFT.title);
        await commentBoxButton(page, 'Add comment').click();

        const draft = page.locator(`${PENDING_DRAFTS} [data-testid="draft"][data-draft-id]`);
        await expect(draft).toContainText('Pending');
        await expect(draft).toContainText('General');
        await expect(draft.locator('[data-testid="draft-title"]')).toHaveText(DRAFT.title);
        await expect(draft).toContainText(DRAFT.body);
        await expect(page.locator(NEW_REQUEST_BOX)).toHaveCount(0);
        await expect(page.locator(SUBMIT_FEEDBACK)).toContainText('1');
        expect(api.callsTo('POST', '/api/v1/reviews/:reviewId/requests'), 'A draft is not a request.').toEqual([]);

        await submitFeedback(page);

        await expect(page.locator(REQUEST_ROWS).filter({ hasText: DRAFT.title }).locator('[data-testid="general-request"]')).toHaveText('General');
        await expect(page.locator(PENDING_DRAFTS)).toHaveCount(0);
        expect(api.bodies('POST', '/api/v1/reviews/:reviewId/drafts')).toEqual([{ kind: 'new_request', anchor: null, title: DRAFT.title, body: DRAFT.body, requireApproval: true }]);
    });

    test('Ctrl+Enter in the New request box adds a draft without a title key', async ({ page, api }) => {
        const review = new FakeReview(api);

        await writeNewRequest(page, review, 'Add a full-screen focus mode.');
        await page.locator(NEW_REQUEST_BOX).press('ControlOrMeta+Enter');

        await expect(page.locator(`${PENDING_DRAFTS} [data-testid="draft"][data-draft-id]`)).toContainText('Add a full-screen focus mode.');
        expect(api.bodies('POST', '/api/v1/reviews/:reviewId/drafts')).toEqual([{ kind: 'new_request', anchor: null, body: 'Add a full-screen focus mode.', requireApproval: true }]);
        expect(api.callsTo('POST', '/api/v1/reviews/:reviewId/requests'), 'The web UI opens no request with Q2.').toEqual([]);
    });

    test('A closed review has no New request button', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.close();

        await page.goto(`/reviews/${review.id}/requests`);

        await expect(page.locator('[data-testid="request-filters"]')).toBeVisible();
        await expect(page.getByRole('button', { name: 'New request', exact: true })).toHaveCount(0);
    });
});
