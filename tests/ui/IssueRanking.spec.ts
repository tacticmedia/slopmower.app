import type { Page } from '@playwright/test';
import { issueDetail } from './support/builders';
import { FakeIssues } from './support/fakeIssues';
import type { MockApi } from './support/mockApi';
import { expect, test } from './support/test';

const RANK = '/api/v1/issues/:issueKey/rank';

/** The bugs BILL-12 and BILL-13, which has no activity, and the changes BILL-14 to BILL-16, each in this rank order. */
async function openList(page: Page, api: MockApi): Promise<FakeIssues> {
    const issues = new FakeIssues(api);
    issues.add(issueDetail());
    issues.add(issueDetail({ key: 'BILL-13', title: 'Round the shipping totals once', status: 'draft', waitingFor: 'requester', claim: null, lastActivityAt: null }));
    issues.add(issueDetail({ key: 'BILL-14', title: 'Show the rounding mode on the invoice PDF', category: 'change', claim: null }));
    issues.add(issueDetail({ key: 'BILL-15', title: 'Name the rounding mode in the README', category: 'change', claim: null }));
    issues.add(issueDetail({ key: 'BILL-16', title: 'Round the totals of credit notes', category: 'change', claim: null }));
    await page.goto('/projects/BILL');
    await expect(page.getByTestId('issue-row')).toHaveCount(5);

    return issues;
}

function changes(page: Page) {
    return page.getByRole('list', { name: 'Change requests' }).getByTestId('issue-row');
}

async function chooseMove(page: Page, issueKey: string, name: string): Promise<void> {
    await page.getByRole('button', { name: `Move ${issueKey}` }).click();
    await page.getByRole('menuitem', { name }).click();
}

test.describe('Issue ranking', () => {
    test('The issue list has the bug reports and the change requests, each in rank order, with the age of the last activity', async ({ page, api }) => {
        await openList(page, api);
        const bugs = page.getByRole('list', { name: 'Bug reports' }).getByTestId('issue-row');

        await expect(bugs).toHaveText([/BILL-12.*active/, /BILL-13.*No activity yet/]);
        await expect(changes(page)).toHaveText([/BILL-14/, /BILL-15/, /BILL-16/]);
    });

    test('"Move up" sends I13 with before and the key of the row above', async ({ page, api }) => {
        await openList(page, api);

        await chooseMove(page, 'BILL-15', 'Move up');

        await expect(changes(page)).toHaveText([/BILL-15/, /BILL-14/, /BILL-16/]);
        expect(api.callsTo('POST', RANK).map(({ params, body }) => [params.issueKey, body])).toEqual([['BILL-15', { before: 'BILL-14' }]]);
    });

    test('"Move to top" and "Move up" are off on the first row', async ({ page, api }) => {
        await openList(page, api);

        await page.getByRole('button', { name: 'Move BILL-14' }).click();

        await expect(page.getByRole('menuitem', { name: 'Move to top' })).toBeDisabled();
        await expect(page.getByRole('menuitem', { name: 'Move up' })).toBeDisabled();
        await expect(page.getByRole('menuitem', { name: 'Move to bottom' })).toBeEnabled();
    });

    test('A drag of the handle to the upper half of a row sends I13 with before, and a drop on the other list sends nothing', async ({ page, api }) => {
        await openList(page, api);

        await page.getByRole('button', { name: 'Move BILL-16' }).dragTo(changes(page).first(), { targetPosition: { x: 40, y: 4 } });
        await expect(changes(page)).toHaveText([/BILL-16/, /BILL-14/, /BILL-15/]);
        await page.getByRole('button', { name: 'Move BILL-13' }).dragTo(changes(page).first(), { targetPosition: { x: 40, y: 4 } });

        await expect(changes(page)).toHaveText([/BILL-16/, /BILL-14/, /BILL-15/]);
        expect(api.bodies('POST', RANK)).toEqual([{ before: 'BILL-14' }]);
    });

    test('A move with the keyboard says the new place, and the focus stays on the handle', async ({ page, api }) => {
        await openList(page, api);
        const handle = page.getByRole('button', { name: 'Move BILL-16' });

        await handle.focus();
        await page.keyboard.press('Enter');
        await expect(page.getByRole('menuitem', { name: 'Move to top' })).toBeFocused();
        await page.keyboard.press('Enter');

        await expect(changes(page)).toHaveText([/BILL-16/, /BILL-14/, /BILL-15/]);
        await expect(page.getByRole('list', { name: 'Change requests' }).locator('..').getByRole('status')).toHaveText('BILL-16 is now 1 of 3.');
        await expect(handle).toBeFocused();
    });

    test('The "Activity" menu sets one activity filter of I1, and the sort "Last update" shows the update age and no handle', async ({ page, api }) => {
        await openList(page, api);
        const lastQuery = () => api.callsTo('GET', '/api/v1/projects/:projectKey/issues').at(-1)?.query;

        await page.getByRole('button', { name: 'Activity: Any' }).click();
        await page.getByRole('menuitemradio', { name: 'None for 3 days' }).click();
        await expect(page).toHaveURL('/projects/BILL?idleForHours=72');
        await page.getByRole('button', { name: 'Activity: None for 3 days' }).click();
        await page.getByRole('menuitemradio', { name: 'Last 24 hours' }).click();
        await expect(page).toHaveURL('/projects/BILL?activeWithinHours=24');
        expect([lastQuery()?.get('activeWithinHours'), lastQuery()?.has('idleForHours')]).toEqual(['24', false]);
        await page.getByRole('button', { name: 'Activity: Last 24 hours' }).click();
        await page.getByRole('menuitemradio', { name: 'Any' }).click();
        await page.getByRole('button', { name: 'Sort: Rank' }).click();
        await page.getByRole('menuitemradio', { name: 'Last update' }).click();

        await expect(page).toHaveURL('/projects/BILL?sort=updated');
        expect(lastQuery()?.get('sort')).toBe('updated');
        await expect(page.getByRole('button', { name: 'Status: All' })).toBeVisible();
        await expect(page.getByRole('button', { name: /^Move / })).toHaveCount(0);
        await expect(page.getByTestId('issue-row')).toHaveText(Array(5).fill(/updated/));
    });
});
