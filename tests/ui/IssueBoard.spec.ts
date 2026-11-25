import type { Locator, Page } from '@playwright/test';
import type { IssueStatus, WaitingFor } from '../../assets/api/types/common';
import type { Issue } from '../../assets/api/types/issue';
import { issueDetail, now } from './support/builders';
import { FakeIssues } from './support/fakeIssues';
import type { MockApi } from './support/mockApi';
import { expect, test } from './support/test';

const WAITING_FOR: Record<IssueStatus, WaitingFor | null> = {
    draft: 'requester',
    backlog: null,
    todo: 'assignee',
    in_progress: 'assignee',
    in_review: 'requester',
    approved: 'assignee',
    done: null,
    wont_do: null,
};

/** `BILL-12` of the fixture in progress, a bug, changed two hours ago. */
function boardIssues(api: MockApi, overrides: Partial<Issue> = {}): FakeIssues {
    const issues = new FakeIssues(api);
    issues.add(issueDetail({ updatedAt: now(-7200), ...overrides }));

    return issues;
}

function addIssue(issues: FakeIssues, key: string, status: IssueStatus, updatedAt = now(-3600)): void {
    issues.add(issueDetail({ key, title: `Issue ${key}`, category: 'change', status, waitingFor: WAITING_FOR[status], claim: null, updatedAt }));
}

/** As an agent with `set_issue_status`. */
function changeIssue(issues: FakeIssues, key: string, status: IssueStatus): void {
    const detail = issues.issues.get(key);
    if (undefined === detail) {
        throw new Error(`No issue ${key}.`);
    }
    const { issue } = detail;
    issues.add({ ...detail, issue: { ...issue, status, waitingFor: WAITING_FOR[status], updatedAt: now() } });
}

function column(page: Page, name: string): Locator {
    return page.getByRole('list', { name: new RegExp(`^${name} \\d+$`) });
}

function card(scope: Page | Locator, key: string): Locator {
    return scope.getByRole('link', { name: new RegExp(`^${key} `) });
}

/** The page reads I1 once more after the first open of its live connection. A change of the fake after it waits for a push. */
async function openBoard(page: Page, api: MockApi): Promise<void> {
    await page.goto('/projects/BILL/board');
    await expect(page.locator('[data-live="connected"]')).toBeAttached();
    await expect.poll(() => api.callsTo('GET', '/api/v1/projects/:projectKey/issues').length).toBe(2);
}

test.describe('Board of a project', () => {
    test('Seven columns stand in the order of the statuses, each card under its status, without a card of the backlog, and the line below counts the backlog', async ({ page, api }) => {
        const issues = boardIssues(api);
        for (const [key, status] of [
            ['BILL-13', 'draft'],
            ['BILL-14', 'todo'],
            ['BILL-15', 'in_review'],
            ['BILL-16', 'approved'],
            ['BILL-17', 'done'],
            ['BILL-18', 'wont_do'],
            ['BILL-19', 'backlog'],
            ['BILL-20', 'backlog'],
        ] as const) {
            addIssue(issues, key, status);
        }

        await openBoard(page, api);

        await expect(page.getByRole('heading', { level: 2 })).toHaveText(['Draft 1', 'To do 1', 'In progress 1', 'In review 1', 'Approved 1', 'Done 1', "Won't do 1"]);
        for (const [key, name] of [
            ['BILL-13', 'Draft'],
            ['BILL-14', 'To do'],
            ['BILL-12', 'In progress'],
            ['BILL-15', 'In review'],
            ['BILL-16', 'Approved'],
            ['BILL-17', 'Done'],
            ['BILL-18', "Won't do"],
        ] as const) {
            await expect(card(column(page, name), key)).toBeVisible();
        }
        await expect(card(page, 'BILL-19')).toHaveCount(0);
        await page.getByRole('link', { name: '2 issues in the backlog' }).click();
        await expect(page).toHaveURL('/projects/BILL?status=backlog');
    });

    test('A card shows the key, the title, the party that it waits for, "Bug report", "No approval", and the age, and opens its issue', async ({ page, api }) => {
        boardIssues(api, { approval: 'self' });
        await openBoard(page, api);
        const bill12 = card(page, 'BILL-12');

        await expect(bill12).toContainText('BILL-12 Round invoice totals once per invoice');
        await expect(bill12.locator('[data-testid="waiting-label"], [data-testid="bug-label"], [data-testid="no-approval"]')).toHaveText(['Waiting for the agent', 'Bug report', 'No approval']);
        await expect(bill12.locator('relative-time')).toContainText('2 hours ago');
        await bill12.click();

        await expect(page).toHaveURL('/issues/BILL-12');
    });

    test('"Done" with 12 issues shows the 10 newest cards, the total, and a link to the list of done issues, and "Won\'t do" with 10 issues has no link', async ({ page, api }) => {
        const issues = boardIssues(api);
        for (let number = 30; number < 42; number++) {
            addIssue(issues, `BILL-${number}`, 'done', now(number * 60 - 3600));
        }
        for (let number = 50; number < 60; number++) {
            addIssue(issues, `BILL-${number}`, 'wont_do');
        }
        await openBoard(page, api);
        const done = column(page, 'Done');

        await expect(done).toHaveAccessibleName('Done 12');
        await expect(done.getByRole('link')).toHaveCount(10);
        await expect(done.getByRole('link').first()).toHaveAccessibleName(/^BILL-41 /);
        await expect(column(page, "Won't do").getByRole('link')).toHaveCount(10);
        await expect(page.getByRole('link', { name: /^All \d+ issues in / })).toHaveText(['All 12 issues in Done']);
        await page.getByRole('link', { name: 'All 12 issues in Done' }).click();

        await expect(page).toHaveURL('/projects/BILL?status=done');
    });

    test('An empty column says "No issues", and without an issue in the backlog no line counts it', async ({ page, api }) => {
        boardIssues(api);
        await openBoard(page, api);

        await expect(page.getByTestId('board-column').filter({ hasText: /^Draft 0/ })).toContainText('No issues');
        await expect(page.getByText('No issues', { exact: true })).toHaveCount(6);
        await expect(page.getByText(/in the backlog$/)).toHaveCount(0);
    });

    test('A pushed update moves a card to its new column without a page load, the status text names the move, and a focus outside the cards stays', async ({ page, api, live }) => {
        const issues = boardIssues(api);
        await openBoard(page, api);
        await page.evaluate(() => Object.assign(window, { boardLoaded: true }));
        const back = page.getByRole('link', { name: 'Issues of Billing' });
        await back.focus();

        changeIssue(issues, 'BILL-12', 'in_review');
        await live.push({ type: 'issue', issueKey: 'BILL-12' });

        await expect(card(column(page, 'In review'), 'BILL-12')).toBeVisible();
        await expect(page.getByTestId('board-column').filter({ hasText: /^In progress 0/ })).toContainText('No issues');
        await expect(page.getByRole('status').filter({ hasText: 'BILL-12 moved to In review.' })).toBeAttached();
        expect(await page.evaluate(() => 'boardLoaded' in window)).toBe(true);
        await expect(back).toBeFocused();
    });

    test('A card with the focus keeps it after an update moved it to another column', async ({ page, api, live }) => {
        const issues = boardIssues(api);
        await openBoard(page, api);
        await card(page, 'BILL-12').focus();

        changeIssue(issues, 'BILL-12', 'approved');
        await live.push({ type: 'issue', issueKey: 'BILL-12' });

        await expect(card(column(page, 'Approved'), 'BILL-12')).toBeFocused();
    });

    test('At 375 px the columns stand one below the other, and the page does not scroll sideways', async ({ page, api }) => {
        boardIssues(api);
        await page.setViewportSize({ width: 375, height: 800 });
        await openBoard(page, api);

        const [first, second] = await Promise.all([0, 1].map((index) => page.getByTestId('board-column').nth(index).boundingBox()));

        expect(second?.x).toBe(first?.x);
        expect(second?.y ?? 0).toBeGreaterThan((first?.y ?? 0) + (first?.height ?? 0));
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    });

    test('The project screen links to the board', async ({ page, api }) => {
        boardIssues(api);
        await page.goto('/projects/BILL');

        await page.getByRole('link', { name: 'Board', exact: true }).click();

        await expect(page).toHaveURL('/projects/BILL/board');
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Board of Billing');
    });
});
