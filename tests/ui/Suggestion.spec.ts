import type { Page } from '@playwright/test';
import { fixture } from './support/builders';
import { openFile, SAVED_THREAD } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { DETAIL, DETAIL_NEXT_STEP, DETAIL_THREAD, openRequest, REQUEST_ROWS, submitNoteDialog } from './support/requestScreens';
import { expect, test } from './support/test';

const TITLE = 'Round half cents up';

const BODY = 'Use `PHP_ROUND_HALF_UP`.';

const { note: NOTE } = fixture<{ note: string }>('inputs.dismiss-suggestion.json');

const LABEL = '[data-testid="suggestion"]';

const FILE_HEADER_LABEL = `[data-testid="file-header"] ${LABEL}`;

const CODE_THREAD = `[data-testid="code-view"] ${SAVED_THREAD}`;

const ACCEPT = '/api/v1/requests/:requestId/accept-suggestion';

function suggestion(review: FakeReview): string {
    return review.agentSuggests({ kind: 'line', path: SMALL_FILE, range: { startLine: 11, endLine: 11 } }, TITLE, BODY).id;
}

function actions(page: Page, container: string) {
    return page.locator(`${container} [data-testid="suggestion-actions"]`);
}

test.describe('Suggestion', () => {
    test('Suggestion shows in the request list, the file header, and the thread', async ({ page, api }) => {
        const review = new FakeReview(api);
        suggestion(review);
        await page.goto(`/reviews/${review.id}/requests`);

        await expect(page.locator(`${REQUEST_ROWS} ${LABEL}`)).toHaveText('Suggestion');

        await openFile(page, review, SMALL_FILE);
        await expect(page.locator(FILE_HEADER_LABEL)).toHaveText('Suggestion');
        await expect(page.locator(`${CODE_THREAD} header ${LABEL}`)).toHaveText('Suggestion');
        await expect(page.locator(`${CODE_THREAD} [data-testid="thread-comment"]`)).toContainText('suggested');
    });

    test('Accept in the code view sends Q21 with Ask for a plan first, and the labels are gone', async ({ page, api }) => {
        const review = new FakeReview(api);
        suggestion(review);
        await openFile(page, review, SMALL_FILE);
        const threadActions = actions(page, CODE_THREAD);
        await expect(threadActions.getByRole('checkbox', { name: 'Ask for a plan first', exact: true })).toBeChecked();

        await threadActions.getByRole('button', { name: 'Accept', exact: true }).click();

        await expect(page.locator(CODE_THREAD)).toContainText('accepted the suggestion');
        await expect(page.locator(FILE_HEADER_LABEL)).toHaveCount(0);
        await expect(page.locator(`${CODE_THREAD} ${LABEL}`)).toHaveCount(0);
        await expect(threadActions).toHaveCount(0);
        expect(api.bodies('POST', ACCEPT)).toEqual([{ requireApproval: true }]);
    });

    test('Accept without Ask for a plan first sends Q21 with false', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openRequest(page, suggestion(review));
        const detailActions = actions(page, DETAIL);

        await detailActions.getByRole('checkbox', { name: 'Ask for a plan first', exact: true }).uncheck();
        await detailActions.getByRole('button', { name: 'Accept', exact: true }).click();

        await expect(page.locator(DETAIL_THREAD)).toContainText('accepted the suggestion');
        await expect(page.locator(DETAIL_NEXT_STEP)).toHaveText('Waiting for an agent to claim this.');
        await expect(detailActions).toHaveCount(0);
        expect(api.bodies('POST', ACCEPT)).toEqual([{ requireApproval: false }]);
    });

    test('Dismiss sends Q22 with the note', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openRequest(page, suggestion(review));

        await actions(page, DETAIL).getByRole('button', { name: 'Dismiss', exact: true }).click();
        await submitNoteDialog(page, NOTE, 'Dismiss');

        await expect(page.locator(DETAIL_THREAD)).toContainText('dismissed the suggestion');
        await expect(page.locator(DETAIL_THREAD)).toContainText(NOTE);
        await expect(page.locator(DETAIL_NEXT_STEP)).toHaveText("Closed as won't do.");
        expect(api.bodies('POST', '/api/v1/requests/:requestId/dismiss-suggestion')).toEqual([{ note: NOTE }]);
    });

    test('The Suggestions filter sends suggestion=pending', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.seedGeneral('Add a CSV export of the invoice totals.');
        suggestion(review);
        await page.goto(`/reviews/${review.id}/requests`);
        const rows = page.locator(REQUEST_ROWS);
        await expect(rows).toHaveCount(2);

        await page.locator('[data-testid="request-filters"]').getByRole('button', { name: 'Filters', exact: true }).click();
        await page.getByRole('menuitemcheckbox', { name: 'Suggestions', exact: true }).click();

        await expect(rows).toHaveCount(1);
        await expect(rows).toContainText(TITLE);
        expect(api.callsTo('GET', '/api/v1/reviews/:reviewId/requests').at(-1)?.query.get('suggestion')).toBe('pending');
    });
});
