import { commentBoxButton } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { expect, test } from './support/test';

const NEW_REQUEST = 'form[aria-label="New request"]';
const DRAFTS = '/api/v1/reviews/:reviewId/drafts';
const REQUESTS = '/api/v1/reviews/:reviewId/requests';

test.describe('CommentGroup', () => {
    // Playwright cannot open the native suggestion list, so the test checks the options that the field offers.
    test('The Group field suggests the group labels of the pending drafts once each', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.seedDraft({ kind: 'new_request', anchor: null, body: 'Use Money in total().', group: 'Money' });
        review.seedDraft({ kind: 'new_request', anchor: null, body: 'Use Money in tax().', group: 'Money' });
        review.seedDraft({ kind: 'new_request', anchor: null, body: 'Wrap long lines.' });
        await page.goto(`/reviews/${review.id}/requests`);

        await page.getByRole('button', { name: 'New request', exact: true }).click();
        const group = page.locator(NEW_REQUEST).getByRole('combobox', { name: 'Group' });
        const listId = await group.getAttribute('list');

        await expect(page.locator(`datalist[id="${listId}"] option`)).toHaveCount(1);
        await expect(page.locator(`datalist[id="${listId}"] option`)).toHaveAttribute('value', 'Money');
    });

    test('The group goes into the D2 body trimmed', async ({ page, api }) => {
        const review = new FakeReview(api);
        await page.goto(`/reviews/${review.id}/requests`);

        await page.getByRole('button', { name: 'New request', exact: true }).click();
        await page.locator(`${NEW_REQUEST} textarea`).fill('Use Money in total().');
        await page.locator(NEW_REQUEST).getByRole('combobox', { name: 'Group' }).fill(' Money ');
        await commentBoxButton(page, 'Add comment').click();

        await expect.poll(() => api.bodies('POST', DRAFTS)).toEqual([{ kind: 'new_request', anchor: null, body: 'Use Money in total().', requireApproval: true, type: 'change', group: 'Money' }]);
        await expect(page.locator('[data-testid="pending-drafts"] [data-testid="draft-group"]')).toHaveText('Group: Money');
    });

    test('The group of a request row opens the request list of that group', async ({ page, api }) => {
        const review = new FakeReview(api);
        const total = review.seedGeneral('Use Money in total().', false, null, 'change', 'Money');
        const tax = review.seedGeneral('Use Money in tax().', false, null, 'change', 'Money');
        review.seedGeneral('Wrap long lines.');
        await page.goto(`/reviews/${review.id}/requests`);
        await expect(page.locator('[data-testid="request-row"]')).toHaveCount(3);

        await page.locator(`[data-testid="request-row"][data-request-id="${total.id}"]`).getByTestId('request-group').click();

        await expect(page).toHaveURL(`/reviews/${review.id}/requests?group=Money`);
        await expect(page.locator('[data-testid="request-row"]')).toHaveCount(2);
        await expect(page.locator(`[data-testid="request-row"][data-request-id="${tax.id}"]`)).toBeVisible();
        expect(api.callsTo('GET', REQUESTS).at(-1)?.query.get('group')).toBe('Money');
    });
});
