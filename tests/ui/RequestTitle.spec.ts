import type { Page } from '@playwright/test';
import type { TitleInput } from '../../assets/api/types/inputs';
import { fixture } from './support/builders';
import { FakeReview } from './support/fakeReview';
import { DETAIL, DETAIL_THREAD, openRequest } from './support/requestScreens';
import { expect, test } from './support/test';

const { title: TITLE, body: BODY } = fixture<{ title: string; body: string }>('inputs.open-general-request.json');

const { title: NEW_TITLE } = fixture<TitleInput>('inputs.title.json');

const Q23 = '/api/v1/requests/:requestId/title';

function heading(page: Page) {
    return page.locator(DETAIL).getByRole('heading', { level: 1 });
}

function button(page: Page, name: string) {
    return page.locator(DETAIL).getByRole('button', { name, exact: true });
}

function titleField(page: Page) {
    return page.locator(DETAIL).getByRole('textbox', { name: 'Title', exact: true });
}

test.describe('RequestTitle', () => {
    test('Edit and Save send Q23, and the heading and the thread show the new title', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openRequest(page, review.seedGeneral(BODY, false, TITLE).id);
        await expect(heading(page)).toHaveText(TITLE);

        await button(page, 'Edit').click();
        await expect(titleField(page)).toHaveValue(TITLE);
        await expect(titleField(page)).toBeFocused();
        await titleField(page).fill(NEW_TITLE);
        await button(page, 'Save').click();

        await expect(heading(page)).toHaveText(NEW_TITLE);
        await expect(page.locator(DETAIL_THREAD)).toContainText('set the title');
        expect(api.bodies('POST', Q23)).toEqual([{ title: NEW_TITLE }]);
    });

    test('Escape cancels the edit without a write, and the focus returns to Edit', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openRequest(page, review.seedGeneral(BODY, false, TITLE).id);

        await button(page, 'Edit').click();
        await titleField(page).fill(NEW_TITLE);
        await page.keyboard.press('Escape');

        await expect(heading(page)).toHaveText(TITLE);
        await expect(button(page, 'Edit')).toBeFocused();
        expect(api.callsTo('POST', Q23)).toEqual([]);
    });

    test('An untitled request shows its first line and Add a title, and Enter saves the title', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openRequest(page, review.seedGeneral(BODY).id);
        await expect(heading(page)).toHaveText('Add a full-screen focus mode.');

        await button(page, 'Add a title').click();
        await expect(titleField(page)).toHaveValue('');
        await titleField(page).fill(NEW_TITLE);
        await page.keyboard.press('Enter');

        await expect(heading(page)).toHaveText(NEW_TITLE);
        await expect(button(page, 'Edit')).toBeVisible();
        expect(api.bodies('POST', Q23)).toEqual([{ title: NEW_TITLE }]);
    });

    test('The browser title names the request, and on the review pages the review', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openRequest(page, review.seedGeneral(BODY, false, TITLE).id);
        await expect(page).toHaveTitle(`${TITLE} · Slopmower`);

        await page.locator(DETAIL).getByRole('link', { name: 'Requests of this review' }).click();

        await expect(page).toHaveTitle(`${review.review.title} · Slopmower`);
    });

    test('A request of a closed review has no Edit button', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedGeneral(BODY, false, TITLE);
        review.close();

        await openRequest(page, request.id);

        await expect(heading(page)).toHaveText(TITLE);
        await expect(button(page, 'Edit')).toHaveCount(0);
    });
});
