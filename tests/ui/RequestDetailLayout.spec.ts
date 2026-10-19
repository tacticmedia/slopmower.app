import type { Page } from '@playwright/test';
import { FakeReview } from './support/fakeReview';
import { ROUND_FQN, SMALL_FILE, SYMBOL_FILE } from './support/files';
import { clickRequestButton, DETAIL, DETAIL_NEXT_STEP, DETAIL_SIDEBAR, DETAIL_STATUS, DETAIL_THREAD, openRequest, PLAN_STATE } from './support/requestScreens';
import { expect, test } from './support/test';

const ACTION_BAR = `${DETAIL} [data-testid="action-bar"]`;

async function box(page: Page, selector: string): Promise<{ top: number; bottom: number }> {
    const found = await page.locator(selector).boundingBox();
    if (null === found) {
        throw new Error(`${selector} is not visible.`);
    }

    return { top: found.y, bottom: found.y + found.height };
}

test.describe('RequestDetailLayout', () => {
    test('On a long thread the action bar stays in the window with the status, and Resolve conversation sends Q12', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        review.agentClaims(request.id);
        for (let reply = 1; reply <= 30; reply++) {
            review.command(request.id, 'comments', { body: `Reply ${reply} on the tax calculation.` });
        }
        review.agentMarksReady(request.id, 'Extracted `tax()`.');
        await openRequest(page, request.id);
        await expect(page.locator(`${ACTION_BAR} [data-testid="stuck-status"]`)).toHaveCount(0);

        await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));

        await expect(page.locator(ACTION_BAR)).toBeInViewport();
        await expect(page.locator(`${ACTION_BAR} [data-testid="stuck-status"]`)).toHaveText('Ready for review');
        await clickRequestButton(page, 'Resolve conversation');
        await expect(page.locator(DETAIL_STATUS)).toHaveText('Verified');
        expect(api.bodies('POST', '/api/v1/requests/:requestId/verify')).toEqual([{}]);
    });

    test('A finished request with an approved plan shows Approved, and no text says that a plan is required', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedGeneral('Add a CSV export of the invoice totals.', true);
        review.agentClaims(request.id);
        const { eventId } = review.agentProposes(request.id, 'Write the CSV in a new class.');
        review.command(request.id, 'approve-proposal', { proposalEventId: eventId });
        review.agentMarksReady(request.id, 'Added the export.');

        await openRequest(page, request.id);

        await expect(page.locator(PLAN_STATE)).toHaveText('Approved');
        await expect(page.locator(DETAIL)).not.toContainText(/required/i);
    });

    test('The next-step line says what happens next on each request that waits for the user', async ({ page, api }) => {
        const review = new FakeReview(api);
        const suggested = review.agentSuggests(null, 'Round half cents up', 'Use `PHP_ROUND_HALF_UP`.');
        const planned = review.seedGeneral('Add a CSV export of the invoice totals.', true);
        review.agentClaims(planned.id);
        review.agentProposes(planned.id, 'Write the CSV in a new class.');
        const ready = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        review.agentClaims(ready.id);
        review.agentMarksReady(ready.id, 'Extracted `tax()`.');
        const asked = review.seedLines(SMALL_FILE, 3, 3, 'Is strict_types needed here?');
        review.agentClaims(asked.id);
        review.agentAsks(asked.id, 'Should the other files declare it too?');

        for (const [requestId, sentence] of [
            [suggested.id, 'The agent suggested this change. Accept it so that an agent can work on it, or dismiss it.'],
            [planned.id, 'The agent proposed a plan. Approve it or request changes.'],
            [ready.id, 'The agent finished. Check the changes, then resolve the conversation or reopen it.'],
            [asked.id, 'The agent asked a question. Reply to continue.'],
        ] as const) {
            await openRequest(page, requestId);
            await expect(page.locator(DETAIL_NEXT_STEP)).toHaveText(sentence);
            await expect(page.locator(DETAIL_NEXT_STEP)).toHaveAttribute('data-awaiting', 'user');
        }
    });

    test('The plan toggle in the sidebar sends Q18 with the new value, and the Plan section follows it', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedGeneral('Add a CSV export of the invoice totals.');
        review.agentClaims(request.id);
        await openRequest(page, request.id);
        const toggle = (name: string) => page.locator(DETAIL_SIDEBAR).getByRole('button', { name, exact: true });
        await expect(page.locator(PLAN_STATE)).toHaveText('Not required');

        await toggle('Ask for a plan first').click();

        await expect(page.locator(PLAN_STATE)).toHaveText('Required before code changes');
        await expect(page.locator(DETAIL_THREAD)).toContainText('asked for a plan first');

        await toggle("Don't ask for a plan").click();

        await expect(page.locator(PLAN_STATE)).toHaveText('Not required');
        await expect(page.locator(DETAIL_THREAD)).toContainText('no longer asks for a plan');
        expect(api.bodies('POST', '/api/v1/requests/:requestId/plan-requirement')).toEqual([{ requireApproval: true }, { requireApproval: false }]);
    });

    test('The Location section of the sidebar shows the path and lines of the anchor once', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');

        await openRequest(page, request.id);

        const location = page.locator(DETAIL_SIDEBAR).locator('section').filter({ has: page.getByRole('heading', { name: 'Location', exact: true }) });
        await expect(location.getByRole('link', { name: `${SMALL_FILE}:L9-L12`, exact: true })).toBeVisible();
        await expect(location.locator('[data-testid="location-info"]')).toHaveAttribute('data-status', 'exact');
        expect((await location.textContent())?.split(SMALL_FILE).length).toBe(2);
    });

    test('The state line leaves out the comment count while the request has no comment', async ({ page, api }) => {
        const review = new FakeReview(api);
        const silent = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        const commented = review.seedLines(SMALL_FILE, 3, 3, 'Is strict_types needed here?');
        review.command(commented.id, 'comments', { body: 'It is.' });
        const stateLine = page.locator(`${DETAIL} > header p`).filter({ has: page.locator('[data-testid="status-label"]') });

        await openRequest(page, silent.id);
        await expect(stateLine).not.toContainText('comment');

        await openRequest(page, commented.id);
        await expect(stateLine).toContainText('· 1 comment');
    });

    test('At 390 px the sidebar follows the header before the tabs, and the page does not scroll sideways', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedSymbol(SYMBOL_FILE, ROUND_FQN, 'Round half to even.');
        review.agentClaims(request.id);
        review.agentLinksPullRequest(request.id, 'https://github.com/acme/billing/pull/42');
        await page.setViewportSize({ width: 390, height: 844 });

        await openRequest(page, request.id);

        const header = await box(page, `${DETAIL} > header`);
        const sidebar = await box(page, DETAIL_SIDEBAR);
        const tabs = await box(page, `${DETAIL} nav[aria-label="Request"]`);
        expect(sidebar.top).toBeGreaterThanOrEqual(header.bottom);
        expect(sidebar.bottom).toBeLessThanOrEqual(tabs.top);
        await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBe(0);
    });
});
