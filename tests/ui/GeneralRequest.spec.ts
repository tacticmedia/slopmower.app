import type { Page } from '@playwright/test';
import { fixture } from './support/builders';
import { commentBoxButton } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { REQUEST_ROWS } from './support/requestScreens';
import { expect, test } from './support/test';

const NEW_REQUEST_BOX = 'form[aria-label="New request"] textarea';
const PENDING_DRAFTS = '[data-testid="pending-drafts"]';
const FINISH = '[data-testid="finish-review"]';

type TitledText = { title: string; body: string };

const OPEN = fixture<TitledText>('inputs.open-general-request.json');

const DRAFT = fixture<TitledText>('inputs.create-draft-general-request.json');

async function writeNewRequest(page: Page, review: FakeReview, body: string, button: string, title: string | null = null): Promise<void> {
    await page.goto(`/reviews/${review.id}/requests`);
    await page.getByRole('button', { name: 'New request', exact: true }).click();
    if (null !== title) {
        await page.locator('form[aria-label="New request"]').getByRole('textbox', { name: 'Title', exact: true }).fill(title);
    }
    await page.locator(NEW_REQUEST_BOX).fill(body);
    await commentBoxButton(page, button).click();
}

test.describe('GeneralRequest', () => {
    test('New request opens a general request shown as General', async ({ page, api }) => {
        const review = new FakeReview(api);

        await writeNewRequest(page, review, 'Add a full-screen focus mode.', 'Add single comment');

        const row = page.locator(REQUEST_ROWS).filter({ hasText: 'Add a full-screen focus mode.' });
        await expect(row.locator('[data-testid="general-request"]')).toHaveText('General');
        await expect(page.locator(NEW_REQUEST_BOX)).toHaveCount(0);
        expect(api.bodies('POST', '/api/v1/reviews/:reviewId/requests')).toEqual([{ anchor: null, body: 'Add a full-screen focus mode.', requireApproval: true }]);
    });

    test('A title goes with the new request, and the list row shows the title', async ({ page, api }) => {
        const review = new FakeReview(api);

        await writeNewRequest(page, review, OPEN.body, 'Add single comment', OPEN.title);

        await expect(page.locator(REQUEST_ROWS).filter({ hasText: OPEN.title }).locator('[data-testid="general-request"]')).toHaveText('General');
        expect(api.bodies('POST', '/api/v1/reviews/:reviewId/requests')).toEqual([{ anchor: null, title: OPEN.title, body: OPEN.body, requireApproval: true }]);
    });

    test('A closed review has no New request button', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.close();

        await page.goto(`/reviews/${review.id}/requests`);

        await expect(page.locator('[data-testid="request-filters"]')).toBeVisible();
        await expect(page.getByRole('button', { name: 'New request', exact: true })).toHaveCount(0);
    });

    test('A general draft joins the batch and keeps its title', async ({ page, api }) => {
        const review = new FakeReview(api);

        await writeNewRequest(page, review, DRAFT.body, 'Start a review', DRAFT.title);

        const draft = page.locator(`${PENDING_DRAFTS} [data-testid="draft"][data-draft-id]`);
        await expect(draft).toContainText('Pending');
        await expect(draft).toContainText('General');
        await expect(draft.locator('[data-testid="draft-title"]')).toHaveText(DRAFT.title);
        await expect(draft).toContainText(DRAFT.body);
        await expect(page.locator(FINISH)).toContainText('1');
        expect(api.callsTo('POST', '/api/v1/reviews/:reviewId/requests'), 'A draft is not a request.').toEqual([]);

        await page.locator(FINISH).click();
        await page.locator('form[data-testid="finish-review-form"]').getByRole('button', { name: 'Submit review' }).click();

        await expect(page.locator(REQUEST_ROWS).filter({ hasText: DRAFT.title }).locator('[data-testid="general-request"]')).toHaveText('General');
        await expect(page.locator(PENDING_DRAFTS)).toHaveCount(0);
        expect(api.bodies('POST', '/api/v1/reviews/:reviewId/drafts')).toEqual([{ kind: 'new_request', anchor: null, title: DRAFT.title, body: DRAFT.body, requireApproval: true }]);
    });
});
