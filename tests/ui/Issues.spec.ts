import type { Locator, Page } from '@playwright/test';
import { issueDetail } from './support/builders';
import { FakeIssues } from './support/fakeIssues';
import { apiError, type MockApi } from './support/mockApi';
import { expect, test } from './support/test';

const ISSUE_KEY = 'BILL-12';
const PLAN_REVISION = '01K7F3C5D6E7F8G9H0J1K2M3N4';
const COMMENTS = '/api/v1/issues/:issueKey/comments';
const PLAN_APPROVAL = '/api/v1/issues/:issueKey/plan-approval';

/** `BILL-12` of `issue.detail.json`: in progress, with a plan that an agent approved. */
async function openIssue(page: Page, api: MockApi): Promise<FakeIssues> {
    const issues = new FakeIssues(api);
    issues.add(issueDetail());
    await page.goto(`/issues/${ISSUE_KEY}`);
    await expect(page.getByTestId('issue-detail')).toBeVisible();

    return issues;
}

function section(page: Page, name: string): Locator {
    return page.locator(`[data-testid="issue-section"][data-section="${name}"]`);
}

test.describe('Issues', () => {
    test('The issue list of a project filters by status', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        issues.add(issueDetail({ key: 'BILL-13', title: 'Show the rounding mode on the invoice PDF', status: 'draft', updatedAt: '2026-10-02T09:04:18Z' }));
        const rows = page.getByTestId('issue-row');
        await page.goto('/projects');
        await page.getByRole('link', { name: 'BILL', exact: true }).click();
        await expect(rows).toHaveText([/Round invoice totals once per invoice/, /Show the rounding mode on the invoice PDF/]);

        await page.getByRole('button', { name: 'Status: All' }).click();
        await page.getByRole('menuitemradio', { name: 'Draft' }).click();

        await expect(page).toHaveURL('/projects/BILL?status=draft');
        await expect(rows).toHaveText([/Show the rounding mode on the invoice PDF.*Draft.*BILL-13/]);
        expect(api.callsTo('GET', '/api/v1/projects/:projectKey/issues').at(-1)?.query.get('status')).toBe('draft');
    });

    test('The issue page shows the four sections as Markdown, the link with its note, and the thread', async ({ page, api }) => {
        await openIssue(page, api);

        await expect(page.getByRole('heading', { level: 1 })).toHaveText(`Round invoice totals once per invoice ${ISSUE_KEY}`);
        await expect(section(page, 'problem').locator('code')).toHaveText('InvoiceTotalsTest');
        await expect(section(page, 'plan').locator('ol > li')).toHaveCount(3);
        await expect(section(page, 'result')).toContainText('Not written yet.');
        await expect(section(page, 'followUps')).toContainText('Not written yet.');
        await expect(page.getByTestId('issue-links')).toContainText('BILL-9 Round shipping totals per line');
        await expect(page.getByTestId('issue-links')).toContainText('BILL-9 owns the shipping totals, which this issue leaves alone.');
        await expect(page.getByTestId('issue-entry')).toHaveText([
            /claude-code laptop.*changed the problem/,
            /claude-code laptop.*changed the plan/,
            /claude-code laptop.*approved the plan, reviewed by plan-reviewer,.*The plan covers the rounding and its test\./,
            /claude-code laptop.*changed the status from To do to In progress/,
            /claude-code laptop.*commented.*InvoiceTotals::round\(\) stays as a deprecated wrapper/,
            /claude-code laptop.*commented.*TaxRounding::perInvoice\(\) is in\. The test is next\./,
        ]);
    });

    test('A comment shows its label next to its actor, and the label Comment shows no badge', async ({ page, api }) => {
        await openIssue(page, api);
        const comments = page.locator('[data-testid="issue-entry"][data-kind="comment"]');

        await expect(comments).toHaveCount(2);
        await expect(comments.first().getByTestId('comment-label')).toHaveText('Decision');
        await expect(comments.last().getByTestId('comment-label')).toHaveCount(0);
    });

    test('The label filter of the thread shows only the comments of that label', async ({ page, api }) => {
        await openIssue(page, api);
        const entries = page.getByTestId('issue-entry');

        await page.getByRole('button', { name: 'Label: All' }).click();
        await page.getByRole('menuitemradio', { name: 'Decision' }).click();

        await expect(page).toHaveURL(`/issues/${ISSUE_KEY}?label=decision`);
        await expect(entries).toHaveText([/Decision.*InvoiceTotals::round\(\) stays as a deprecated wrapper/]);

        await page.getByRole('button', { name: 'Label: Decision' }).click();
        await page.getByRole('menuitemradio', { name: 'Papercut' }).click();

        await expect(page.getByTestId('issue-thread-empty')).toContainText('No comment has this label.');
        await page.getByRole('link', { name: 'Clear the filter' }).click();
        await expect(entries).toHaveCount(6);
    });

    test('A comment posts its body with the label comment and shows in the thread as the comment of the user', async ({ page, api }) => {
        await openIssue(page, api);
        const field = page.getByRole('textbox', { name: 'Comment' });

        await field.fill('Keep `round()` as deprecated.');
        await page.getByRole('button', { name: 'Comment', exact: true }).click();

        await expect(page.getByTestId('issue-entry').last()).toHaveText(/jan commented.*Keep round\(\) as deprecated\./);
        await expect(field).toHaveValue('');
        expect(api.bodies('POST', COMMENTS)).toEqual([{ body: 'Keep `round()` as deprecated.', label: 'comment' }]);
    });

    test('The comment form posts the chosen label, and the select returns to Comment', async ({ page, api }) => {
        await openIssue(page, api);
        const label = page.getByRole('combobox', { name: 'Label' });

        await label.selectOption('Decision');
        await page.getByRole('textbox', { name: 'Comment' }).fill('Keep `round()` as deprecated.');
        await page.getByRole('button', { name: 'Comment', exact: true }).click();

        await expect(page.getByTestId('issue-entry').last()).toHaveText(/jan Decision commented.*Keep round\(\) as deprecated\./);
        await expect(label).toHaveValue('comment');
        expect(api.bodies('POST', COMMENTS)).toEqual([{ body: 'Keep `round()` as deprecated.', label: 'decision' }]);
    });

    test('A refused comment keeps the chosen label and the text', async ({ page, api }) => {
        await openIssue(page, api);
        const message = 'This value is too long. It should have 65536 characters or less.';
        api.on('POST', COMMENTS, () => apiError(422, 'invalid_input', `Invalid input. body: ${message}`, { violations: [{ field: 'body', message }] }));
        const label = page.getByRole('combobox', { name: 'Label' });
        const field = page.getByRole('textbox', { name: 'Comment' });

        await label.selectOption('Decision');
        await field.fill('Keep `round()` as deprecated.');
        await page.getByRole('button', { name: 'Comment', exact: true }).click();

        await expect(page.getByTestId('comment-error')).toContainText(message);
        await expect(label).toHaveValue('decision');
        await expect(field).toHaveValue('Keep `round()` as deprecated.');
    });

    test('The comments page of a project filters by label and links each comment to its issue', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        const rows = page.getByTestId('comment-row');
        await page.goto('/projects/BILL');
        await page.getByRole('link', { name: 'Comments', exact: true }).click();
        await expect(page).toHaveURL('/projects/BILL/comments');
        await expect(rows).toHaveText([/Round invoice totals once per invoice.*BILL-12.*TaxRounding::perInvoice\(\) is in/, /Round invoice totals once per invoice.*Decision.*BILL-12.*InvoiceTotals::round\(\) stays/]);

        await page.getByRole('button', { name: 'Label: All' }).click();
        await page.getByRole('menuitemradio', { name: 'Decision' }).click();

        await expect(page).toHaveURL('/projects/BILL/comments?label=decision');
        await expect(rows).toHaveCount(1);
        expect(api.callsTo('GET', '/api/v1/projects/:projectKey/comments').at(-1)?.query.get('label')).toBe('decision');
        await rows.getByRole('link', { name: 'Round invoice totals once per invoice' }).click();
        await expect(page).toHaveURL(`/issues/${ISSUE_KEY}`);
    });

    test('"Approve plan" shows for a plan that an agent approved and posts the plan revision of the page', async ({ page, api }) => {
        await openIssue(page, api);
        await expect(page.getByTestId('approval-state')).toContainText('Approved by claude-code laptop');

        await page.getByRole('button', { name: 'Approve plan' }).click();

        await expect(page.getByTestId('approval-state')).toContainText('Approved by jan');
        await expect(page.getByRole('button', { name: 'Approve plan' })).toHaveCount(0);
        expect(api.bodies('POST', PLAN_APPROVAL)).toEqual([{ planRevision: PLAN_REVISION }]);
    });

    test('A plan of spaces only has no "Approve plan"', async ({ page, api }) => {
        const issues = await openIssue(page, api);

        issues.changePlan(ISSUE_KEY, '   ');
        await page.reload();

        await expect(section(page, 'plan')).toContainText('Not written yet.');
        await expect(page.getByRole('button', { name: 'Approve plan' })).toHaveCount(0);
    });

    test('A refused approval shows the message of the server and the newest plan, which the next click approves', async ({ page, api }) => {
        const issues = await openIssue(page, api);
        issues.changePlan(ISSUE_KEY, 'Round once per invoice line.');
        const approve = page.getByRole('button', { name: 'Approve plan' });

        await approve.click();

        await expect(section(page, 'plan')).toContainText('Round once per invoice line.');
        await expect(page.getByTestId('action-error')).toContainText('The plan changed after this revision. Read the plan again, and approve its newest revision.');
        await approve.click();
        await expect(page.getByTestId('approval-state')).toContainText('Approved by jan');
        expect(api.bodies('POST', PLAN_APPROVAL)).toEqual([{ planRevision: PLAN_REVISION }, { planRevision: issues.issues.get(ISSUE_KEY)?.issue.planRevision }]);
    });
});
