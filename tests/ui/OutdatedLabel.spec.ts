import { sha1 } from './support/builders';
import { openFile, SAVED_THREAD } from './support/codeView';
import { FakeRepositories } from './support/fakeRepositories';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { DETAIL_SIDEBAR, OUTDATED, openRequest, REQUEST_ROWS } from './support/requestScreens';
import { expect, test } from './support/test';

/** The default-branch commit that edits line 11, inside the anchored lines 9 to 12. */
const EDIT_OF_LINE_11 = sha1('Use percent');

test.describe('OutdatedLabel', () => {
    test('After a default-branch commit edits the region and the user clicks Refresh, the request shows Outdated', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        const repository = repositories.addLocal('e2e-outdated');
        const review = repositories.addReview(repository);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        repositories.onFetch = () => review.markOutdated(request.id, EDIT_OF_LINE_11);
        await page.goto(`/repositories/${repository.id}`);

        await page.getByRole('button', { name: 'Refresh' }).click();

        await expect.poll(() => api.callsTo('POST', '/api/v1/repositories/:repositoryId/fetch').map(({ params }) => params.repositoryId)).toEqual([repository.id]);
        await openRequest(page, request.id);
        await expect(page.locator(`${DETAIL_SIDEBAR} ${OUTDATED}`)).toHaveText('Outdated');
        await expect(page.locator('[data-testid="location-info"]')).toHaveAttribute('data-status', 'changed');
        await openFile(page, review, SMALL_FILE);
        await expect(page.locator(`${SAVED_THREAD} ${OUTDATED}`)).toHaveText('Outdated');
    });

    test('The outdated filter lists the request', async ({ page, api }) => {
        const review = new FakeReview(api);
        const outdated = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        review.seedLines(SMALL_FILE, 3, 3, 'Is strict_types needed here?');
        review.markOutdated(outdated.id, EDIT_OF_LINE_11);
        await page.goto(`/reviews/${review.id}/requests`);
        const rows = page.locator(REQUEST_ROWS);
        await expect(rows).toHaveCount(2);

        await page.locator('[data-testid="request-filters"] label').filter({ hasText: /^Outdated$/ }).click();

        await expect(rows).toHaveCount(1);
        await expect(page).toHaveURL(/outdated=true/);
        expect(api.callsTo('GET', '/api/v1/reviews/:reviewId/requests').at(-1)?.query.get('outdated')).toBe('true');
        await expect(rows).toContainText('Extract the tax calculation.');
        await expect(rows.locator(OUTDATED)).toHaveText('Outdated');
    });
});
