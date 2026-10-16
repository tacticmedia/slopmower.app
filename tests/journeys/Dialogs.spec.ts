import type { Page } from '@playwright/test';
import type { CarryOverInput } from '../../assets/api/types/inputs';
import { carryOverResult, now } from '../ui/support/builders';
import { FakeRepositories } from '../ui/support/fakeRepositories';
import { SMALL_FILE } from '../ui/support/files';
import { json } from '../ui/support/mockApi';
import { clickRequestButton, openRequest } from '../ui/support/requestScreens';
import { chooseReviewAction, reviewActions } from '../ui/support/reviewHeader';
import { test } from './support/test';

function dialog(page: Page) {
    return page.getByRole('dialog');
}

test.describe('Dialogs', () => {
    test('Each dialog asks for its note or confirmation before the change', async ({ page, api, captureScreen }) => {
        const repositories = new FakeRepositories(api);
        const repository = repositories.addLocal('billing');
        const source = repositories.addReview(repository, { review: { title: 'Release 2.3', createdAt: now(-86_400) } });
        const review = repositories.addReview(repository, { review: { title: 'Release 2.4', createdAt: now() } });
        api.on('POST', '/api/v1/reviews/:reviewId/carry-over', ({ params, body }) => json('carry-over-result.schema.json', carryOverResult(params.reviewId ?? '', (body as CarryOverInput).fromReviewId)));

        const ready = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        review.agentClaims(ready.id);
        review.agentMarksReady(ready.id, 'The calculation is one line, so I left it.');
        await openRequest(page, ready.id);
        await clickRequestButton(page, 'Reopen');
        await dialog(page).locator('textarea').fill('Please extract it anyway.');
        await captureScreen('Reopen', dialog(page).getByRole('button', { name: 'Reopen', exact: true }));

        const open = review.seedLines(SMALL_FILE, 3, 3, 'Is strict_types needed here?');
        await openRequest(page, open.id);
        await clickRequestButton(page, "Close as won't do");
        await dialog(page).locator('textarea').fill('Every file of the project declares strict types.');
        await captureScreen("Won't do", dialog(page).getByRole('button', { name: "Close as won't do", exact: true }));

        const claimed = review.seedGeneral('Add a CSV export of the invoice totals.');
        review.agentClaims(claimed.id);
        await openRequest(page, claimed.id);
        await clickRequestButton(page, 'Unassign');
        await captureScreen('Unassign', dialog(page).getByRole('button', { name: 'Unassign', exact: true }));

        review.seedDraft({ kind: 'new_request', anchor: null, body: 'Add a changelog entry.' });
        await page.goto(`/reviews/${review.id}`);
        await reviewActions(page).click();
        await captureScreen('Review actions', page.getByRole('menuitem', { name: 'Delete review', exact: true }));
        await page.getByRole('menuitem', { name: 'Close review', exact: true }).click();
        await dialog(page).locator('textarea[name="note"]').fill('Shipped in 2.4.');
        await captureScreen('Close review', dialog(page).locator('[data-testid="close-drafts-warning"]'));

        await page.goto(`/reviews/${review.id}`);
        await chooseReviewAction(page, 'Delete review');
        await captureScreen('Delete review', dialog(page).locator('button[type="submit"]'));

        await page.goto(`/reviews/${review.id}`);
        await chooseReviewAction(page, 'Carry over requests');
        await dialog(page).locator('select[name="fromReviewId"]').selectOption({ label: source.review.title });
        await captureScreen('Carry over', dialog(page).locator('select[name="fromReviewId"]'));

        await dialog(page).locator('button[type="submit"]').click();
        await captureScreen('Carry over result', dialog(page).locator('[data-testid="carry-over-result"]'));
    });
});
