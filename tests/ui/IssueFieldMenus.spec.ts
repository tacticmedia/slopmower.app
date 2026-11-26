import type { Page } from '@playwright/test';
import { issueDetail } from './support/builders';
import { FakeIssues } from './support/fakeIssues';
import { apiError, type MockApi } from './support/mockApi';
import { expect, test } from './support/test';

const ISSUE_KEY = 'BILL-12';
const ISSUE = '/api/v1/issues/:issueKey';
const COMMENTS = '/api/v1/issues/:issueKey/comments';

/** `BILL-12` of `issue.detail.json`: a bug in a project of the mode "Agent review", with a user as the requester and the agent as the assignee. */
async function openIssue(page: Page, api: MockApi): Promise<void> {
    new FakeIssues(api).add(issueDetail());
    await page.goto(`/issues/${ISSUE_KEY}`);
    await expect(page.getByTestId('issue-detail')).toBeVisible();
}

/** `BILL-12` with User approval in a project of the mode "Per issue", and `BILL-13` in a project of the mode "Agent review". */
async function openPerIssueApproval(page: Page, api: MockApi): Promise<void> {
    const issues = new FakeIssues(api);
    issues.add(issueDetail({ approval: 'user', project: { key: 'BILL', name: 'Billing', approvalMode: 'by_issue' } }));
    issues.add(issueDetail({ key: 'BILL-13' }));
    await page.goto(`/issues/${ISSUE_KEY}`);
    await expect(page.getByTestId('issue-detail')).toBeVisible();
}

async function chooseApproval(page: Page, name: string): Promise<void> {
    await page.getByTestId('issue-approval').click();
    await page.getByRole('menuitemradio', { name }).click();
    await expect(page.getByRole('menu')).toHaveCount(0);
}

test.describe('IssueFieldMenus', () => {
    test('The requester and assignee menus send I7, and the waiting party follows the assignee', async ({ page, api }) => {
        await openIssue(page, api);
        const assignee = page.getByTestId('issue-assignee');
        const requester = page.getByTestId('issue-requester');
        await expect(assignee).toHaveAccessibleName('Assignee: Agent');
        await expect(requester).toHaveAccessibleName('Requester: jan');

        await assignee.click();
        await page.getByRole('menuitemradio', { name: 'jan' }).click();
        await expect(page.getByTestId('waiting-label').first()).toHaveText('Waiting for jan');
        await requester.click();
        await page.getByRole('menuitemradio', { name: 'Agent' }).click();

        await expect(requester).toHaveAccessibleName('Requester: Agent');
        expect(api.bodies('PATCH', ISSUE)).toEqual([{ assignee: 'user' }, { requester: 'agent' }]);
    });

    test('The approval menu lists the three modes with what each does, and checks the current one', async ({ page, api }) => {
        await openPerIssueApproval(page, api);
        const modes = page.getByRole('menuitemradio');

        await page.getByRole('button', { name: 'Approval: User approval' }).click();

        await expect(modes).toHaveText([/^User approval/, /^Agent review/, /^No approval/]);
        await expect(modes.nth(0)).toHaveAttribute('aria-checked', 'true');
        await expect(modes.nth(0)).toHaveAccessibleDescription('You approve the plan and accept the work. An agent cannot end the issue without you.');
        await expect(modes.nth(1)).toHaveAccessibleDescription('A second agent reviews the plan and the work. Then the agent approves the plan and ends the issue, with the review on record.');
        await expect(modes.nth(2)).toHaveAccessibleDescription('The agent starts without a plan approval. It checks its own work and ends the issue.');
    });

    test('The approval menu sends I7 only for a mode other than the current one', async ({ page, api }) => {
        await openPerIssueApproval(page, api);
        const approval = page.getByTestId('issue-approval');

        await chooseApproval(page, 'No approval');
        await expect(approval).toHaveAccessibleName('Approval: No approval');
        await chooseApproval(page, 'No approval');
        await chooseApproval(page, 'Agent review');

        await expect(approval).toHaveAccessibleName('Approval: Agent review');
        expect(api.bodies('PATCH', ISSUE)).toEqual([{ approval: 'self' }, { approval: 'agent_review' }]);
    });

    test('A choice while a write runs is not sent, and the button keeps the first choice', async ({ page, api }) => {
        await openPerIssueApproval(page, api);
        const approval = page.getByTestId('issue-approval');
        let release = (): void => undefined;
        const held = new Promise<void>((resolve) => {
            release = resolve;
        });
        await page.route(`**${ISSUE.replace(':issueKey', ISSUE_KEY)}`, async (route) => {
            if ('PATCH' === route.request().method()) {
                await held;
            }
            await route.fallback();
        });

        await chooseApproval(page, 'No approval');
        await expect(approval).toHaveAttribute('aria-disabled', 'true');
        await approval.press('Enter');
        await page.getByRole('menuitemradio', { name: 'Agent review' }).click();
        await expect(page.getByRole('menu')).toHaveCount(0);
        await expect(approval).toContainText('No approval');
        release();

        await expect(approval).toHaveAccessibleName('Approval: No approval');
        expect(api.bodies('PATCH', ISSUE)).toEqual([{ approval: 'self' }]);
    });

    test('A line below the approval says what its mode does, in every project mode', async ({ page, api }) => {
        await openPerIssueApproval(page, api);
        const summary = page.getByTestId('issue-approval-summary');
        await expect(summary).toHaveText('You approve the plan and accept the work. An agent cannot end the issue without you.');

        await page.goto('/issues/BILL-13');

        await expect(summary).toHaveText('A second agent reviews the plan and the work. Then the agent approves the plan and ends the issue, with the review on record.');
    });

    test('The approval is text in a project of another mode', async ({ page, api }) => {
        await openPerIssueApproval(page, api);

        await page.goto('/issues/BILL-13');

        await expect(page.getByTestId('issue-approval')).toHaveText('Agent review');
        await expect(page.getByRole('button', { name: /^Approval:/ })).toHaveCount(0);
    });

    test('The category menu sends I7, and the header then shows no "Bug report"', async ({ page, api }) => {
        await openIssue(page, api);
        const category = page.getByTestId('issue-category');
        await expect(page.getByTestId('bug-label')).toHaveText('Bug report');
        await expect(category).toHaveAccessibleName('Category: Bug report');

        await category.click();
        await page.getByRole('menuitemradio', { name: 'Change request' }).click();

        await expect(category).toHaveAccessibleName('Category: Change request');
        await expect(page.getByTestId('bug-label')).toHaveCount(0);
        expect(api.bodies('PATCH', ISSUE)).toEqual([{ category: 'change' }]);
    });

    test('The comment label menu lists the labels that a user writes, each with its meaning', async ({ page, api }) => {
        await openIssue(page, api);
        const labels = page.getByRole('menuitemradio');

        await page.getByRole('button', { name: 'Comment label: Comment' }).click();

        await expect(labels, 'The server sets Question, Answer, and Dismissal.').toHaveText([/^Comment/, /^Decision/, /^Finding/, /^Deviation/, /^Scope creep/, /^Papercut/, /^Review/]);
        await expect(labels.nth(0)).toHaveAttribute('aria-checked', 'true');
        await expect(labels.nth(1)).toHaveAccessibleDescription('A choice, with its reason and what it rules out.');
    });

    test('The comment form posts the chosen label, and the menu returns to Comment', async ({ page, api }) => {
        await openIssue(page, api);

        await page.getByRole('button', { name: 'Comment label: Comment' }).click();
        await page.getByRole('menuitemradio', { name: 'Decision' }).click();
        await page.getByRole('textbox', { name: 'Comment' }).fill('Keep `round()` as deprecated.');
        await page.getByRole('button', { name: 'Comment', exact: true }).click();

        await expect(page.getByTestId('issue-entry').last()).toHaveText(/jan Decision commented.*Keep round\(\) as deprecated\./);
        await expect(page.getByRole('button', { name: 'Comment label: Comment' })).toBeVisible();
        expect(api.bodies('POST', COMMENTS)).toEqual([{ body: 'Keep `round()` as deprecated.', label: 'decision' }]);
    });

    test('Mod+Enter in the comment label menu sends no comment', async ({ page, api }) => {
        await openIssue(page, api);
        const labelMenu = page.getByRole('button', { name: /^Comment label: / });
        await page.getByRole('textbox', { name: 'Comment' }).fill('Keep `round()` as deprecated.');

        await labelMenu.click();
        await page.getByRole('menuitemradio', { name: 'Decision' }).press('ControlOrMeta+Enter');
        // Primer selects an item on `keypress`, which Meta+Enter does not fire: the menu can still be open.
        await page.keyboard.press('Escape');
        await labelMenu.click();
        await page.getByRole('menuitemradio', { name: 'Decision' }).click();
        await page.getByRole('button', { name: 'Comment', exact: true }).click();

        await expect(page.getByTestId('issue-entry').last()).toHaveText(/jan Decision commented/);
        expect(api.bodies('POST', COMMENTS), 'Only the button sends.').toEqual([{ body: 'Keep `round()` as deprecated.', label: 'decision' }]);
    });

    test('A refused comment keeps the chosen label and the text', async ({ page, api }) => {
        await openIssue(page, api);
        const message = 'This value is too long. It should have 65536 characters or less.';
        api.on('POST', COMMENTS, () => apiError(422, 'invalid_input', `Invalid input. body: ${message}`, { violations: [{ field: 'body', message }] }));
        const field = page.getByRole('textbox', { name: 'Comment' });

        await page.getByRole('button', { name: 'Comment label: Comment' }).click();
        await page.getByRole('menuitemradio', { name: 'Decision' }).click();
        await field.fill('Keep `round()` as deprecated.');
        await page.getByRole('button', { name: 'Comment', exact: true }).click();

        await expect(page.getByTestId('comment-error')).toContainText(message);
        await expect(page.getByRole('button', { name: 'Comment label: Decision' })).toBeVisible();
        await expect(field).toHaveValue('Keep `round()` as deprecated.');
    });
});
