import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { clickRequestButton, DETAIL_STATUS, DETAIL_THREAD, openRequest } from './support/requestScreens';
import { expect, test } from './support/test';

const LABEL = "Close as won't do";

test.describe('WontDo', () => {
    test("Close as won't do stays disabled below 20 characters, then closes the request as Won't do", async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        await openRequest(page, request.id);

        await clickRequestButton(page, LABEL);
        const textarea = page.getByRole('dialog').locator('textarea');
        const submit = page.getByRole('dialog').getByRole('button', { name: LABEL, exact: true });
        await textarea.fill('  Already done in #42');
        await expect(submit, '19 characters after trimming are too few.').toBeDisabled();
        await textarea.pressSequentially('.');
        await expect(submit).toBeEnabled();
        await submit.click();

        await expect(page.locator(DETAIL_STATUS)).toHaveText("Won't do");
        await expect(page.locator(DETAIL_THREAD)).toContainText('Already done in #42.');
        expect(api.bodies('POST', '/api/v1/requests/:requestId/wont-do')).toEqual([{ reason: 'Already done in #42.' }]);
    });
});
