import type { Page } from '@playwright/test';
import type { ProposeSolutionInput } from '../../assets/api/types/inputs';
import { fixture } from './support/builders';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import type { MockApi } from './support/mockApi';
import { REQUEST_ROWS } from './support/requestScreens';
import { expect, test } from './support/test';

const FILTERS = '[data-testid="request-filters"]';

const Q1 = '/api/v1/reviews/:reviewId/requests';

/** 200 characters, most of them one word, so only `overflow-wrap: anywhere` can wrap it. */
const LONG_TITLE = `Extract ${'InvoiceTotalsRoundingStrategy'.repeat(7)}`.slice(0, 200);

const { plan: PLAN } = fixture<Required<ProposeSolutionInput>>('inputs.propose-solution.json');

function lastQuery(api: MockApi): URLSearchParams | undefined {
    return api.callsTo('GET', Q1).at(-1)?.query;
}

function stateLink(page: Page, state: 'Open' | 'Closed') {
    return page.locator(FILTERS).getByRole('link', { name: new RegExp(`^${state}`) });
}

async function chooseFromMenu(page: Page, menu: string, role: 'menuitemradio' | 'menuitemcheckbox', item: string): Promise<void> {
    await page.locator(FILTERS).getByRole('button', { name: menu, exact: true }).click();
    await page.getByRole(role, { name: item, exact: true }).click();
}

/** Two open requests, one waiting for the user, and two closed ones. */
function openAndClosed(review: FakeReview): void {
    review.seedLines(SMALL_FILE, 3, 3, 'Is strict_types needed here?');
    const asked = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
    review.agentClaims(asked.id);
    review.agentAsks(asked.id, 'Should the new method be public?');
    const verified = review.seedGeneral('Wrap long lines in the code view.');
    review.agentClaims(verified.id);
    review.agentMarksReady(verified.id, 'Wrapped.');
    review.command(verified.id, 'verify', {});
    const declined = review.seedLines(SMALL_FILE, 1, 1, 'Drop the opening tag.');
    review.command(declined.id, 'wont-do', { reason: 'Every PHP file needs the opening tag.' });
}

test.describe('RequestListLayout', () => {
    test('A title of 200 characters wraps, and neither the list nor the page scrolls sideways at 390 px and at 1280 px', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.seedGeneral('Split the class.', false, LONG_TITLE);
        review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        await page.goto(`/reviews/${review.id}/requests`);
        const title = page.locator(REQUEST_ROWS).getByRole('link', { name: LONG_TITLE, exact: true });

        for (const width of [390, 1280]) {
            await page.setViewportSize({ width, height: 900 });

            await expect.poll(() => title.evaluate((link) => link.getClientRects().length), `The title wraps at ${width} px.`).toBeGreaterThan(1);
            const overflow = await page.locator('[data-testid="request-list"]').evaluate((list) => ({
                list: list.scrollWidth - list.clientWidth,
                page: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            }));
            expect(overflow, `No sideways scroll at ${width} px.`).toEqual({ list: 0, page: 0 });
        }
    });

    test('The page opens on Open with awaiting=user,agent and the review counts, and Closed sends awaiting=none', async ({ page, api }) => {
        const review = new FakeReview(api);
        openAndClosed(review);
        await page.goto(`/reviews/${review.id}/requests`);
        const rows = page.locator(REQUEST_ROWS);

        await expect(rows).toHaveCount(2);
        await expect(stateLink(page, 'Open')).toHaveAttribute('aria-current', 'true');
        await expect(stateLink(page, 'Open')).toHaveAccessibleName('Open (2)');
        await expect(stateLink(page, 'Closed')).toHaveAccessibleName('Closed (2)');
        expect(lastQuery(api)?.get('awaiting')).toBe('user,agent');

        await stateLink(page, 'Closed').click();

        await expect(page).toHaveURL(/awaiting=none/);
        await expect(rows.filter({ hasText: 'Is strict_types needed here?' })).toHaveCount(0);
        const statuses = await rows.locator('[data-testid="status-label"]').evaluateAll((icons) => icons.map((icon) => icon.getAttribute('data-status')));
        expect(statuses.toSorted()).toEqual(['verified', 'wont_do']);
        await expect(stateLink(page, 'Closed')).toHaveAttribute('aria-current', 'true');
        await expect(stateLink(page, 'Open')).toHaveAccessibleName('Open (2)');
        expect(lastQuery(api)?.get('awaiting')).toBe('none');
    });

    test('Waiting for you sends awaiting=user, and the Closed tab has no such toggle', async ({ page, api }) => {
        const review = new FakeReview(api);
        openAndClosed(review);
        await page.goto(`/reviews/${review.id}/requests`);
        const waiting = page.locator(FILTERS).getByRole('button', { name: 'Waiting for you', exact: true });
        await expect(page.locator(REQUEST_ROWS)).toHaveCount(2);

        await waiting.click();

        await expect(waiting).toHaveAttribute('aria-pressed', 'true');
        await expect(page.locator(REQUEST_ROWS)).toHaveText([/Extract the tax calculation\./]);
        expect(lastQuery(api)?.get('awaiting')).toBe('user');

        await stateLink(page, 'Closed').click();

        await expect(page).toHaveURL(/awaiting=none/);
        await expect(waiting).toHaveCount(0);
    });

    test('The Status, Filters, and Sort menus send status, outdated, suggestion, and sort', async ({ page, api }) => {
        const review = new FakeReview(api);
        openAndClosed(review);
        await page.goto(`/reviews/${review.id}/requests`);
        await expect(page.locator(REQUEST_ROWS)).toHaveCount(2);
        expect(lastQuery(api)?.get('sort'), 'Recently updated is the default.').toBe('updated');

        await chooseFromMenu(page, 'Status', 'menuitemradio', 'In progress');
        await expect(page).toHaveURL(/status=in_progress/);
        await chooseFromMenu(page, 'Filters', 'menuitemcheckbox', 'Outdated');
        await expect(page).toHaveURL(/outdated=true/);
        await chooseFromMenu(page, 'Filters', 'menuitemcheckbox', 'Suggestions');
        await expect(page).toHaveURL(/suggestion=pending/);
        await chooseFromMenu(page, 'Sort', 'menuitemradio', 'Oldest');
        await expect(page).toHaveURL(/sort=created/);

        const query = lastQuery(api);
        expect([query?.get('status'), query?.get('outdated'), query?.get('suggestion'), query?.get('sort'), query?.get('awaiting')]).toEqual(['in_progress', 'true', 'pending', 'created', 'user,agent']);

        await chooseFromMenu(page, 'Sort', 'menuitemradio', 'Path');
        await expect(page).toHaveURL(/sort=path/);
        expect(lastQuery(api)?.get('sort')).toBe('path');
    });

    test('A row shows Waiting for you only while awaiting is user, and Plan to approve only for a pending proposal', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.seedGeneral('Add a CSV export.', false, 'Agent works');
        const asked = review.seedGeneral('Add a PDF export.', false, 'Agent asks');
        review.agentClaims(asked.id);
        review.agentAsks(asked.id, 'Which paper size?');
        const pending = review.seedGeneral('Add an XLSX export.', true, 'Plan pending');
        review.agentClaims(pending.id);
        review.agentProposes(pending.id, PLAN);
        const approved = review.seedGeneral('Add a JSON export.', true, 'Plan approved');
        review.agentClaims(approved.id);
        const { eventId } = review.agentProposes(approved.id, PLAN);
        review.command(approved.id, 'approve-proposal', { proposalEventId: eventId });
        await page.goto(`/reviews/${review.id}/requests`);
        const labels = (title: string, testId: string) => page.locator(REQUEST_ROWS).filter({ hasText: title }).locator(`[data-testid="${testId}"]`);
        await expect(page.locator(REQUEST_ROWS)).toHaveCount(4);

        for (const [title, waiting, plan] of [
            ['Agent works', 0, 0],
            ['Agent asks', 1, 0],
            ['Plan pending', 1, 1],
            ['Plan approved', 0, 0],
        ] as const) {
            await expect(labels(title, 'waiting-for-you'), title).toHaveCount(waiting);
            await expect(labels(title, 'plan-to-approve'), title).toHaveCount(plan);
        }
    });

    test('The link of the empty list clears every filter and opens Open', async ({ page, api }) => {
        const review = new FakeReview(api);
        openAndClosed(review);
        await page.goto(`/reviews/${review.id}/requests?awaiting=none&status=open&sort=path`);
        await expect(page.locator('[data-testid="request-list-empty"]')).toContainText('No request matches these filters.');

        await page.getByRole('link', { name: 'Clear the filters', exact: true }).click();

        await expect(page).toHaveURL(`/reviews/${review.id}/requests`);
        await expect(page.locator(REQUEST_ROWS)).toHaveCount(2);
        expect([lastQuery(api)?.get('awaiting'), lastQuery(api)?.get('status'), lastQuery(api)?.get('sort')]).toEqual(['user,agent', null, 'updated']);
    });
});
