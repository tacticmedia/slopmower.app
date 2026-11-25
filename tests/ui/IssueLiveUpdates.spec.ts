import type { Question } from '../../assets/api/types/common';
import { issueDetail, project } from './support/builders';
import { FakeIssues } from './support/fakeIssues';
import { expect, test } from './support/test';

const ISSUE_KEY = 'BILL-12';
const QUESTIONS: Question[] = [
    { question: 'Cover the shipping totals too?', detail: null, answer: null },
    { question: 'Which type holds the amounts?', detail: null, answer: null },
];

test.describe('IssueLiveUpdates', () => {
    test('An issue update shows the new comment of the agent, and the comment form, its label, an open section editor, and the typed answers stay', async ({ page, api, live }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        issues.agentAsks(ISSUE_KEY, 'Two choices before the test.', QUESTIONS);
        await page.goto(`/issues/${ISSUE_KEY}`);
        const form = page.getByRole('form', { name: /^Questions of / });
        const yes = form.getByRole('group', { name: QUESTIONS[0]?.question }).getByRole('button', { name: 'Yes', exact: true });
        const other = form.getByRole('group', { name: QUESTIONS[1]?.question }).getByRole('textbox', { name: 'Other answer' });
        const comment = page.getByRole('textbox', { name: 'Comment' });
        const label = page.getByRole('combobox', { name: 'Label' });
        const result = page.getByRole('textbox', { name: 'Result' });
        await page.getByRole('button', { name: 'Write Result' }).click();
        await result.fill('Added `TaxRounding::perInvoice()`.');
        await yes.click();
        await other.fill('Money');
        await comment.fill('Keep `round()` as deprecated.');
        await label.selectOption('Decision');
        await expect(page.locator('[data-live="connected"]')).toBeAttached();

        issues.agentComments(ISSUE_KEY, 'The test is in.');
        await live.push({ type: 'issue', issueKey: ISSUE_KEY });

        await expect(page.getByTestId('issue-entry').last()).toHaveText(/claude-code laptop.*commented.*The test is in\./);
        await expect(comment).toHaveValue('Keep `round()` as deprecated.');
        await expect(label).toHaveValue('decision');
        await expect(result).toHaveValue('Added `TaxRounding::perInvoice()`.');
        await expect(yes).toHaveAttribute('aria-pressed', 'true');
        await expect(other).toHaveValue('Money');
    });

    test('A plan of the agent that arrives while the page is open shows the notice, and "Approve plan" sends it only after "Read the new plan"', async ({ page, api, live }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail({ waitingFor: 'requester' }));
        await page.goto(`/issues/${ISSUE_KEY}`);
        const approve = page.getByRole('button', { name: 'Approve plan' });
        const notice = page.getByRole('region', { name: 'The plan changed while this page was open.' });
        await expect(page.locator('[data-live="connected"]')).toBeAttached();
        await page.getByRole('button', { name: 'Request changes' }).click();
        await page.getByRole('textbox', { name: 'Changes to the plan' }).fill('Round the shipping totals in BILL-9.');

        issues.changePlan(ISSUE_KEY, 'Round once per invoice line.');
        await live.push({ type: 'issue', issueKey: ISSUE_KEY });

        await expect(page.locator('[data-testid="issue-section"][data-section="plan"]')).toContainText('Round once per invoice line.');
        await expect(page.locator('[data-testid="issue-entry"][data-kind="section_changed"][data-replaced="true"]'), 'The version that the page showed is replaced.').toHaveCount(2);
        await expect(notice).toBeVisible();
        await expect(page.getByRole('status').filter({ hasText: 'The plan changed while this page was open.' }), 'A screen reader announces it.').toBeAttached();
        await expect(page.getByRole('button', { name: 'Request changes' }), 'The open change box is off too.').toBeDisabled();
        await page.getByRole('button', { name: 'Cancel' }).click();
        await expect(approve).toBeDisabled();
        await notice.getByRole('button', { name: 'Read the new plan' }).click();
        await expect(notice).toHaveCount(0);
        await expect(approve).toBeFocused();
        await approve.click();

        await expect(page.getByTestId('approval-state')).toContainText('Approved by jan');
        expect(api.bodies('POST', '/api/v1/issues/:issueKey/plan-approval')).toEqual([{ planRevision: issues.issues.get(ISSUE_KEY)?.issue.planRevision }]);
    });

    test('The project screens watch the topic of their project, and a move to another project changes it', async ({ page, api, live }) => {
        const issues = new FakeIssues(api);
        issues.projects = [...issues.projects, project({ key: 'DOC', name: 'Documentation', repositories: [] })];
        issues.add(issueDetail());
        await page.goto('/projects/BILL');
        await expect(page.locator('[data-live="connected"]')).toBeAttached();

        issues.add(issueDetail({ key: 'BILL-13', title: 'Show the rounding mode on the invoice PDF', status: 'draft', updatedAt: '2026-10-02T09:04:18Z' }));
        await live.push({ type: 'issue', issueKey: 'BILL-13' });
        await expect(page.getByTestId('issue-row')).toHaveText([/Round invoice totals once per invoice/, /Show the rounding mode on the invoice PDF/]);

        await page.getByRole('link', { name: 'Issues', exact: true }).click();
        await page.getByRole('link', { name: 'DOC', exact: true }).click();
        await expect(page.getByTestId('issue-list-empty')).toBeVisible();

        // The EventSource of the new topic opens after the render of the page.
        await expect(() => live.push({ type: 'issue', issueKey: 'DOC-1' })).toPass();
        await expect(live.push({ type: 'issue', issueKey: ISSUE_KEY }), 'The page left the topic of BILL.').rejects.toThrow('No open EventSource of the page subscribes to');
    });
});
