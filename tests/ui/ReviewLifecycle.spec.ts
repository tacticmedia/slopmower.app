import type { Page } from '@playwright/test';
import { newId } from './support/builders';
import { FakeRepositories } from './support/fakeRepositories';
import { apiError, type MockApi } from './support/mockApi';
import { chooseReviewAction, reviewActions } from './support/reviewHeader';
import { expect, test } from './support/test';

const REVIEW_LIST = '[data-testid="review-list"]';
const START_FORM = '[data-testid="start-review-form"]';
const START = '/api/v1/repositories/:repositoryId/reviews';
const CLOSE = '/api/v1/reviews/:reviewId/close';

/** Holds the requests to the route until the returned function runs, then passes them on to the fake. */
function hold(api: MockApi, method: string, path: string): () => void {
    let release = (): void => undefined;
    const released = new Promise<void>((resolve) => {
        release = resolve;
    });
    api.on(method, path, () => released.then(() => undefined));

    return release;
}

function reviewTitles(page: Page) {
    return page.locator(`${REVIEW_LIST} tbody th`);
}

async function openAgentPrompts(page: Page) {
    await page.locator('header').getByRole('button', { name: 'Agent prompts', exact: true }).click();

    return page.getByRole('dialog', { name: 'Agent prompts' });
}

test.describe('ReviewLifecycle', () => {
    test('Agent prompts shows the review ID and the prompt snippets', async ({ page, api }) => {
        const repository = new FakeRepositories(api).addLocal('e2e-lifecycle');
        await page.goto(`/repositories/${repository.id}`);
        await expect(page.locator(`${START_FORM} input[name="ref"]`)).toHaveValue('main');

        await page.locator(`${START_FORM} button[type="submit"]`).click();

        await expect(page).toHaveURL(/\/reviews\/[0-9A-HJKMNP-TV-Z]{26}$/);
        const reviewId = new URL(page.url()).pathname.split('/').at(-1) ?? '';
        const prompts = await openAgentPrompts(page);
        await expect(prompts.locator('[data-testid="review-id"]')).toHaveText(reviewId);
        await expect(prompts.locator('[data-testid="agent-prompt"]')).toHaveText(`Work on Slopmower review ${reviewId}`);
        await expect(prompts.locator('[data-testid="suggestion-prompt"]')).toHaveText(`Review the code of Slopmower review ${reviewId} and suggest changes`);
        expect(api.bodies('POST', START)).toEqual([{ ref: 'main', title: null }]);
    });

    test('Connect your agent in Agent prompts is a link, not in the muted style of the prompt labels', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        const review = repositories.addReview(repositories.addLocal('e2e-lifecycle'), { review: { title: 'Connect review' } });
        await page.goto(`/reviews/${review.id}`);

        const prompts = await openAgentPrompts(page);

        const link = prompts.getByRole('link', { name: 'Connect your agent', exact: true });
        await expect(link).toHaveAttribute('href', '/docs/connect-your-agent');
        const colour = (text: string) => prompts.getByText(text, { exact: true }).evaluate((element) => getComputedStyle(element).color);
        expect(await colour('Connect your agent')).not.toBe(await colour('Agent prompt'));
    });

    test('The copy buttons of Agent prompts copy the review ID and both prompts', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        const review = repositories.addReview(repositories.addLocal('e2e-lifecycle'), { review: { title: 'Copy review' } });
        // A Playwright context denies clipboard writes by default.
        await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
        await page.goto(`/reviews/${review.id}`);
        const prompts = await openAgentPrompts(page);
        const copies = { 'review-id': review.id, 'agent-prompt': `Work on Slopmower review ${review.id}`, 'suggestion-prompt': `Review the code of Slopmower review ${review.id} and suggest changes` };

        for (const [testId, copied] of Object.entries(copies)) {
            const copyButton = prompts.locator(`[data-testid="${testId}"] + * button`);
            await copyButton.click();

            await expect(copyButton).toContainText('Copied');
            expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(copied);
        }
    });

    test('Two open reviews of one repository are listed', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        const repository = repositories.addLocal('e2e-lifecycle');
        repositories.addReview(repository, { review: { title: 'First review' } });
        repositories.addReview(repository, { review: { title: 'Second review' } });

        await page.goto(`/repositories/${repository.id}`);

        await expect(reviewTitles(page)).toHaveCount(2);
        expect((await reviewTitles(page).allTextContents()).toSorted()).toEqual(['First review', 'Second review']);
    });

    test('After close, the review is hidden, shows with Show closed, and is read-only', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        const repository = repositories.addLocal('e2e-lifecycle');
        const review = repositories.addReview(repository, { review: { title: 'Closing review' } });
        await page.goto(`/reviews/${review.id}`);

        await chooseReviewAction(page, 'Close review');
        await page.locator('[role="dialog"] textarea[name="note"]').fill('Shipped in 2.4');
        await page.locator('[role="dialog"] button[type="submit"]').click();

        await expect(page.locator('[data-testid="review-closed"]')).toContainText('Shipped in 2.4');
        await reviewActions(page).click();
        await expect(page.getByRole('menuitem')).toHaveText(['Delete review']);
        await page.keyboard.press('Escape');
        expect(api.bodies('POST', CLOSE)).toEqual([{ note: 'Shipped in 2.4' }]);

        await page.locator(`header a[href="/repositories/${repository.id}"]`).click();
        await expect(page.locator(REVIEW_LIST)).toContainText('This repository has no open reviews.');
        await page.locator(`${REVIEW_LIST} input[type="checkbox"]`).click();

        await expect(page.locator(`${REVIEW_LIST} table`)).toContainText('Closing review');
        await expect(reviewTitles(page)).toHaveText(['Closing review']);
        await expect(page.locator(`${REVIEW_LIST} table`)).toContainText('Closed');
    });

    test('A double click on Start review starts one review', async ({ page, api }) => {
        const repository = new FakeRepositories(api).addLocal('e2e-lifecycle');
        const release = hold(api, 'POST', START);
        await page.goto(`/repositories/${repository.id}`);

        await page.locator(`${START_FORM} button[type="submit"]`).dblclick();
        release();

        await expect(page).toHaveURL(/\/reviews\/[0-9A-HJKMNP-TV-Z]{26}$/);
        expect(api.callsTo('POST', START)).toHaveLength(1);
    });

    test('The Close review dialog stays open until V4 answers, and closes the review once', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        const review = repositories.addReview(repositories.addLocal('e2e-lifecycle'));
        const release = hold(api, 'POST', CLOSE);
        await page.goto(`/reviews/${review.id}`);
        await chooseReviewAction(page, 'Close review');
        const dialog = page.getByRole('dialog');

        await dialog.locator('button[type="submit"]').dblclick();
        await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        await page.keyboard.press('Escape');

        await expect(dialog).toBeVisible();
        release();
        await expect(page.locator('[data-testid="review-closed"]')).toBeVisible();
        await expect(dialog).toHaveCount(0);
        expect(api.callsTo('POST', CLOSE)).toHaveLength(1);
    });

    test('An unknown review ID shows the route error, and the header stays', async ({ page, api }) => {
        const unknown = newId();
        for (const path of ['', '/drafts', '/viewed-files', '/tree']) {
            api.on('GET', `/api/v1/reviews/:reviewId${path}`, () => apiError(404, 'not_found', `No review ${unknown} exists.`));
        }

        await page.goto(`/reviews/${unknown}`);

        await expect(page.locator('main [data-testid="route-error"]')).toBeVisible();
        await expect(page.locator('header [data-testid="current-user"]')).toHaveText('jan');
    });
});
