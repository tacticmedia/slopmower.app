import type { Locator, Page } from '@playwright/test';
import { issueDetail, project } from './support/builders';
import { FakeIssues } from './support/fakeIssues';
import { FakeRepositories } from './support/fakeRepositories';
import { apiError, type MockApi } from './support/mockApi';
import { expect, test } from './support/test';

const ISSUE_KEY = 'BILL-12';
const PLAN_REVISION = '01K7F3C5D6E7F8G9H0J1K2M3N4';
const COMMENTS = '/api/v1/issues/:issueKey/comments';
const PLAN_APPROVAL = '/api/v1/issues/:issueKey/plan-approval';
const ISSUE = '/api/v1/issues/:issueKey';
const STATUS = '/api/v1/issues/:issueKey/status';

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

async function chooseStatus(page: Page, name: string): Promise<void> {
    await page.getByRole('button', { name: 'Set status' }).click();
    await page.getByRole('menuitem', { name }).click();
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

    test('"New project" shows the error of a taken key at the Key field, and a new project opens its page', async ({ page, api }) => {
        new FakeIssues(api);
        await page.goto('/projects');
        await page.getByRole('button', { name: 'New project' }).click();
        const dialog = page.getByRole('dialog');
        const key = dialog.getByRole('textbox', { name: 'Key' });

        await key.fill('bill');
        await dialog.getByRole('textbox', { name: 'Name' }).fill('Documentation');
        await dialog.getByRole('button', { name: 'Create project' }).click();
        await expect(key).toHaveAccessibleDescription(/The project BILL exists already\./);

        await key.fill('doc');
        await dialog.getByRole('radio', { name: 'No approval' }).check();
        await dialog.getByRole('button', { name: 'Create project' }).click();

        await expect(page).toHaveURL('/projects/DOC');
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Documentation');
        expect(api.bodies('POST', '/api/v1/projects')).toEqual([
            { key: 'BILL', name: 'Documentation', approvalMode: 'user' },
            { key: 'DOC', name: 'Documentation', approvalMode: 'self' },
        ]);
    });

    test('The project settings save the mode and the checked repositories, and a repository of another project is disabled', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        const [, beta] = ['alpha', 'beta', 'gamma'].map((name) => repositories.addLocal(name));
        const issues = new FakeIssues(api, () => repositories.repositories);
        issues.projects = [project({ repositories: ['alpha'] }), project({ key: 'DOC', name: 'Documentation', repositories: ['gamma'] })];
        await page.goto('/projects/BILL');
        await page.getByRole('link', { name: 'Project settings' }).click();
        await expect(page).toHaveURL('/projects/BILL/settings');
        const gamma = page.getByRole('checkbox', { name: 'gamma' });
        await expect(page.getByRole('checkbox', { name: 'alpha' })).toBeChecked();
        await expect(gamma).toBeDisabled();
        await expect(gamma).toHaveAccessibleDescription('Belongs to the project Documentation.');

        await page.getByRole('radio', { name: 'User approval' }).check();
        await page.getByRole('checkbox', { name: 'alpha' }).uncheck();
        await page.getByRole('checkbox', { name: 'beta' }).check();
        await page.getByRole('button', { name: 'Save' }).click();

        await expect(page.getByTestId('settings-saved')).toBeVisible();
        expect(api.bodies('PATCH', '/api/v1/projects/:projectKey')).toEqual([{ name: 'Billing', approvalMode: 'user', repositoryIds: [beta?.id] }]);
        expect(issues.projects[0]).toEqual(project({ approvalMode: 'user', repositories: ['beta'] }));
    });

    test('"New issue" creates a draft with the title and the problem, and opens its page', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        await page.goto('/projects/BILL');
        await page.getByRole('link', { name: 'New issue' }).click();
        await expect(page).toHaveURL('/projects/BILL/issues/new');

        await page.getByRole('textbox', { name: 'Title' }).fill('Show the rounding mode on the invoice PDF');
        await page.getByRole('textbox', { name: 'Problem' }).fill('The PDF does not say how the total rounds.');
        await page.getByRole('button', { name: 'Create issue' }).click();

        await expect(page).toHaveURL('/issues/BILL-13');
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Show the rounding mode on the invoice PDF BILL-13');
        await expect(page.getByTestId('issue-status').first()).toHaveText('Draft');
        await expect(section(page, 'problem')).toContainText('The PDF does not say how the total rounds.');
        expect(api.bodies('POST', '/api/v1/projects/:projectKey/issues')).toEqual([{ title: 'Show the rounding mode on the invoice PDF', problem: 'The PDF does not say how the total rounds.' }]);
    });

    test('"Edit" of the title saves the new title', async ({ page, api }) => {
        await openIssue(page, api);

        await page.getByRole('button', { name: 'Edit', exact: true }).click();
        await page.getByRole('textbox', { name: 'Title' }).fill('Round invoice totals once');
        await page.getByRole('button', { name: 'Save' }).click();

        await expect(page.getByRole('heading', { level: 1 })).toHaveText(`Round invoice totals once ${ISSUE_KEY}`);
        expect(api.bodies('PATCH', ISSUE)).toEqual([{ title: 'Round invoice totals once' }]);
    });

    test('"Edit" of a section saves its text, and Save is off while the text is empty', async ({ page, api }) => {
        await openIssue(page, api);
        const result = section(page, 'result');

        await result.getByRole('button', { name: 'Edit Result' }).click();
        const field = result.getByRole('textbox', { name: 'Result' });
        await expect(result.getByRole('button', { name: 'Save' })).toBeDisabled();
        await field.fill('Added `TaxRounding::perInvoice()` in a1b2c3d.');
        await result.getByRole('button', { name: 'Save' }).click();

        await expect(result.locator('code')).toHaveText('TaxRounding::perInvoice()');
        await expect(field).toHaveCount(0);
        await expect(page.getByTestId('issue-entry').last()).toHaveText(/jan changed the result/);
        expect(api.bodies('PATCH', ISSUE)).toEqual([{ result: 'Added `TaxRounding::perInvoice()` in a1b2c3d.' }]);
    });

    test('A refused section keeps its text in the editor, also after a reload', async ({ page, api }) => {
        await openIssue(page, api);
        const message = 'This value is too long. It should have 65536 characters or less.';
        api.on('PATCH', ISSUE, () => apiError(422, 'invalid_input', `Invalid input. plan: ${message}`, { violations: [{ field: 'plan', message }] }));
        const plan = section(page, 'plan');
        const field = plan.getByRole('textbox', { name: 'Plan' });

        await plan.getByRole('button', { name: 'Edit Plan' }).click();
        await expect(field).toHaveValue(/^1\. Add `TaxRounding::perInvoice\(\)`/);
        await field.fill('Round once per invoice line.');
        await plan.getByRole('button', { name: 'Save' }).click();

        await expect(plan.getByTestId('comment-error')).toContainText(message);
        await expect(field).toHaveValue('Round once per invoice line.');
        await page.reload();
        await expect(field).toHaveValue('Round once per invoice line.');
    });

    test('The status menu lists every status except that of the issue, and sets the chosen one', async ({ page, api }) => {
        await openIssue(page, api);

        await page.getByRole('button', { name: 'Set status' }).click();
        await expect(page.getByRole('menuitem')).toHaveText([/^Draft/, /^Backlog/, /^To do/, /^Waiting for you/, /^In review/, /^Done/, /^Won't do/]);
        await page.getByRole('menuitem', { name: 'To do' }).click();

        await expect(page.getByTestId('issue-status').first()).toHaveText('To do');
        await expect(page.getByTestId('issue-entry').last()).toHaveText(/jan changed the status from In progress to To do/);
        expect(api.bodies('POST', STATUS)).toEqual([{ status: 'todo' }]);
    });

    test('"Won\'t do" asks for a note and sends it with the status', async ({ page, api }) => {
        await openIssue(page, api);

        await chooseStatus(page, "Won't do");
        const dialog = page.getByRole('dialog');
        const submit = dialog.getByRole('button', { name: "Set won't do" });
        await expect(submit).toBeDisabled();
        await dialog.getByRole('textbox', { name: 'Note' }).fill('BILL-9 covers it.');
        await submit.click();

        await expect(dialog).toHaveCount(0);
        await expect(page.getByTestId('issue-status').first()).toHaveText("Won't do");
        await expect(page.getByTestId('issue-entry').last()).toHaveText(/jan changed the status from In progress to Won't do.*BILL-9 covers it\./);
        expect(api.bodies('POST', STATUS)).toEqual([{ status: 'wont_do', note: 'BILL-9 covers it.' }]);
    });

    test('A refused status shows only the reason of the server, and the page shows the newest issue', async ({ page, api }) => {
        const issues = await openIssue(page, api);
        issues.changePlan(ISSUE_KEY, 'Round once per invoice line.');

        await chooseStatus(page, 'Done');

        await expect(page.getByTestId('status-error')).toHaveText('An issue in done needs a result.');
        await expect(page.getByTestId('issue-status').first()).toHaveText('In progress');
        await expect(section(page, 'plan')).toContainText('Round once per invoice line.');
    });

    test('A refusal with two violations shows both reasons, and an error without a violation shows its message', async ({ page, api }) => {
        await openIssue(page, api);
        const violations = [
            { field: 'note', message: 'done needs a note with the review.' },
            { field: 'reviewer', message: 'done needs the name of the reviewer.' },
        ];

        api.on('POST', STATUS, () => apiError(422, 'invalid_input', 'Invalid input. note: done needs a note with the review. reviewer: done needs the name of the reviewer.', { violations }));
        await chooseStatus(page, 'Done');
        await expect(page.getByTestId('status-error')).toHaveText('done needs a note with the review. done needs the name of the reviewer.');

        api.on('POST', STATUS, () => apiError(404, 'not_found', 'The resource does not exist.'));
        await chooseStatus(page, 'Done');
        await expect(page.getByTestId('status-error')).toHaveText('The resource does not exist.');
    });
});
