import type { Locator, Page } from '@playwright/test';
import type { Issue, IssueDetail } from '../../assets/api/types/issue';
import { issueDetail } from './support/builders';
import { FakeIssues } from './support/fakeIssues';
import type { MockApi } from './support/mockApi';
import { expect, test } from './support/test';

/**
 * `BILL-14`, which waits for the user, blocks `BILL-12` in progress, a bug, which blocks `BILL-13`, a draft: the blocker
 * has the higher key, so the key order is not the reading order. `DOC-2` of another project is in review and blocks
 * `BILL-13` too. `BILL-15` is in review and `BILL-16` is a draft, both without a dependency. Each issue but `BILL-12` is a
 * change.
 */
async function openGraph(page: Page, api: MockApi): Promise<void> {
    const issues = new FakeIssues(api);
    const change = (overrides: Partial<Issue>): IssueDetail => issueDetail({ category: 'change', ...overrides });
    issues.add(issueDetail());
    issues.add(change({ key: 'BILL-13', title: 'Show the rounding mode on the invoice PDF', status: 'draft' }));
    issues.add(change({ key: 'BILL-14', title: 'Round the totals of credit notes', status: 'awaiting_user_input' }));
    issues.add(change({ key: 'BILL-15', title: 'Name the rounding mode in the README', status: 'in_review' }));
    issues.add(change({ key: 'BILL-16', title: 'Round the totals of quotes', status: 'draft' }));
    issues.add(change({ key: 'DOC-2', title: 'Name the rounding modes', status: 'in_review', project: { key: 'DOC', name: 'Docs', approvalMode: 'user' } }));
    issues.block('BILL-13', 'BILL-12');
    issues.block('BILL-13', 'DOC-2');
    issues.block('BILL-12', 'BILL-14');
    await page.goto('/projects/BILL');
    await page.getByRole('link', { name: 'Graph', exact: true }).click();
    await expect(page.getByTestId('issue-graph')).toBeVisible();
}

function node(page: Page, key: string): Locator {
    return page.getByRole('link', { name: new RegExp(`^${key}: `) });
}

test.describe('Dependency graph of a project', () => {
    test('A blocker stands left of each issue that waits for it and comes before it in the list, with one arrow per dependency', async ({ page, api }) => {
        await openGraph(page, api);

        const [first, second, third] = await Promise.all(['BILL-14', 'BILL-12', 'BILL-13'].map((key) => node(page, key).boundingBox()));
        const order = await page.getByTestId('graph-node').evaluateAll((nodes) => nodes.map((each) => (each.getAttribute('aria-label') ?? '').split(':')[0]));

        expect((first?.x ?? 0) + (first?.width ?? 0)).toBeLessThan(second?.x ?? 0);
        expect((second?.x ?? 0) + (second?.width ?? 0)).toBeLessThan(third?.x ?? 0);
        expect(order.toSorted()).toEqual(['BILL-12', 'BILL-13', 'BILL-14', 'BILL-15', 'DOC-2']);
        expect(order.indexOf('BILL-14')).toBeLessThan(order.indexOf('BILL-12'));
        expect(order.indexOf('BILL-12')).toBeLessThan(order.indexOf('BILL-13'));
        await expect(page.locator('[data-testid="issue-graph"] svg[aria-hidden="true"] polyline')).toHaveCount(3);
    });

    test('A node says its status, "Bug report" for a bug, that it waits for the user, and the keys that block it, in its name and in its text', async ({ page, api }) => {
        await openGraph(page, api);

        await expect(node(page, 'BILL-12')).toHaveAccessibleName('BILL-12: Round invoice totals once per invoice. In progress. Bug report. Blocked by BILL-14.');
        await expect(node(page, 'BILL-12').getByTestId('bug-label')).toHaveText('Bug report');
        await expect(node(page, 'BILL-13').getByTestId('bug-label')).toHaveCount(0);
        await expect(node(page, 'BILL-15')).toHaveAccessibleName('BILL-15: Name the rounding mode in the README. In review. Waits for you.');
        await expect(node(page, 'BILL-14'), 'The status says it already.').toHaveAccessibleName('BILL-14: Round the totals of credit notes. Waiting for you.');
        await expect(node(page, 'BILL-15')).toContainText('Waits for you');
        await expect(node(page, 'BILL-13')).toContainText('Blocked by BILL-12, DOC-2');
        await expect(node(page, 'BILL-12')).not.toContainText('Waits for you');
    });

    test('The marks are on an issue of the project that waits for the user, and on a node with an open blocker', async ({ page, api }) => {
        await openGraph(page, api);

        for (const key of ['BILL-14', 'BILL-15']) {
            await expect(node(page, key)).toHaveAttribute('data-waits', 'true');
        }
        for (const key of ['BILL-12', 'BILL-13']) {
            await expect(node(page, key)).toHaveAttribute('data-blocked', 'true');
        }
        // I9 has no blocker of an issue of another project, so the page does not say that it is next.
        await expect(node(page, 'DOC-2')).toHaveAccessibleName('DOC-2: Name the rounding modes. In review.');
        await expect(node(page, 'DOC-2')).not.toHaveAttribute('data-waits');
        await expect(node(page, 'BILL-14')).not.toHaveAttribute('data-blocked');
    });

    test('A node opens its issue', async ({ page, api }) => {
        await openGraph(page, api);

        await node(page, 'BILL-13').click();

        await expect(page).toHaveURL('/issues/BILL-13');
        await expect(page.getByRole('heading', { level: 1 })).toContainText('Show the rounding mode on the invoice PDF');
    });

    test('The line under the graph counts the other open issues and opens the list', async ({ page, api }) => {
        await openGraph(page, api);

        await page.getByRole('link', { name: '1 more open issue has no dependency' }).click();

        await expect(page).toHaveURL('/projects/BILL');
        await expect(page.getByTestId('issue-row')).toHaveCount(5);
    });

    test('A project without a dependency and without an issue that waits for the user shows the empty state', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        issues.add(issueDetail({ key: 'BILL-13', title: 'Show the rounding mode on the invoice PDF', status: 'draft' }));

        await page.goto('/projects/BILL/graph');

        await expect(page.getByTestId('issue-graph-empty')).toHaveText('No open issue has a dependency or waits for you.');
        await expect(page.getByRole('link', { name: '2 open issues have no dependency' })).toBeVisible();
        await expect(page.getByTestId('issue-graph')).toHaveCount(0);
    });
});
