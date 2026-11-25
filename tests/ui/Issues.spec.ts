import type { Locator, Page } from '@playwright/test';
import type { Question } from '../../assets/api/types/common';
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
const QUESTIONS: Question[] = [
    { question: 'Cover the shipping totals too?', detail: 'Shipping uses its own **rounding** today.', answer: null },
    { question: 'Which type holds the amounts?', detail: null, answer: null },
];

/** `BILL-12` of `issue.detail.json`: in progress, with a second plan version that an agent approved, and a replaced comment. */
async function openIssue(page: Page, api: MockApi): Promise<FakeIssues> {
    const issues = new FakeIssues(api);
    issues.add(issueDetail());
    await page.goto(`/issues/${ISSUE_KEY}`);
    await expect(page.getByTestId('issue-detail')).toBeVisible();

    return issues;
}

/** The page reads I2 once more after the first open of its live connection. A change of the fake after it waits for a reload. */
async function firstLiveReload(page: Page, api: MockApi): Promise<void> {
    await expect(page.locator('[data-live="connected"]')).toBeAttached();
    await expect.poll(() => api.callsTo('GET', ISSUE).length).toBe(2);
}

/** The text of the current version of a section, in its entry of the timeline. */
function section(page: Page, name: string): Locator {
    return page.locator(`[data-testid="issue-section"][data-section="${name}"]`);
}

function replacedEntries(page: Page, kind: string): Locator {
    return page.locator(`[data-testid="issue-entry"][data-kind="${kind}"][data-replaced="true"]`);
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

    test('The issue list shows "No approval" on an issue with the approval self', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        issues.add(issueDetail({ key: 'BILL-13', title: 'Fix a typo in the README', approval: 'self', updatedAt: '2026-10-02T09:04:18Z' }));
        await page.goto('/projects/BILL');

        await expect(page.getByTestId('issue-row').filter({ has: page.getByTestId('no-approval') })).toHaveText([/Fix a typo in the README.*No approval.*BILL-13/]);
    });

    test('The approval in the sidebar sends I7 in a project of the mode "Per issue", and is text in a project of another mode', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail({ approval: 'user', project: { key: 'BILL', name: 'Billing', approvalMode: 'by_issue' } }));
        issues.add(issueDetail({ key: 'BILL-13' }));
        await page.goto(`/issues/${ISSUE_KEY}`);
        const approval = page.getByRole('combobox', { name: 'Approval of the issue' });
        await expect(approval).toHaveValue('user');

        await approval.selectOption({ label: 'No approval' });

        await expect(approval).toHaveValue('self');
        await expect(approval).toBeEnabled();
        expect(api.bodies('PATCH', ISSUE)).toEqual([{ approval: 'self' }]);
        await page.goto('/issues/BILL-13');
        await expect(page.getByTestId('issue-approval')).toHaveText('Agent review');
    });

    test('The issue page shows each section version as Markdown in the timeline, the row "Current", the link with its note, and each entry', async ({ page, api }) => {
        await openIssue(page, api);
        const current = page.getByTestId('issue-current');

        await expect(page.getByRole('heading', { level: 1 })).toHaveText(`Round invoice totals once per invoice ${ISSUE_KEY}`);
        await expect(section(page, 'problem').locator('code')).toHaveText('InvoiceTotalsTest');
        await expect(section(page, 'plan').locator('ol > li')).toHaveCount(3);
        await expect(current.getByRole('link')).toHaveText(['Problem', 'Plan']);
        await expect(current.getByRole('button', { name: 'Write Result' })).toBeVisible();
        await expect(current.getByRole('button', { name: 'Write Follow-ups' })).toBeVisible();
        await expect(page.getByTestId('issue-links')).toContainText('BILL-9 Round shipping totals per line');
        await expect(page.getByTestId('issue-links')).toContainText('BILL-9 owns the shipping totals, which this issue leaves alone.');
        await expect(page.getByTestId('issue-entry')).toHaveText([
            /claude-code laptop.*Problem changed the problem/,
            /claude-code laptop.*Plan changed the plan.*Replaced/,
            /claude-code laptop.*Plan changed the plan/,
            /claude-code laptop.*approved the plan, reviewed by plan-reviewer,.*The plan covers the rounding and its test\./,
            /claude-code laptop.*changed the status from To do to In progress/,
            /claude-code laptop.*commented.*Replaced/,
            /claude-code laptop.*commented.*InvoiceTotals::round\(\) stays as a deprecated wrapper/,
            /claude-code laptop.*commented.*TaxRounding::perInvoice\(\) is in\. The test is next\./,
        ]);
    });

    test('A replaced plan version is closed and names its successor, and the current one is open with "Approve plan"', async ({ page, api }) => {
        await openIssue(page, api);
        const replaced = replacedEntries(page, 'section_changed');

        await expect(replaced).toHaveCount(1);
        await expect(replaced.getByText('Round the total in')).toBeHidden();
        await expect(replaced.getByRole('link', { name: 'Newer version' })).toHaveAttribute('href', `/issues/${ISSUE_KEY}#entry-${PLAN_REVISION}`);
        await expect(section(page, 'plan').locator('ol > li').first()).toBeVisible();
        await expect(section(page, 'plan').getByRole('button', { name: 'Approve plan' })).toBeVisible();
    });

    test('A link of the row "Current" scrolls to the entry of its section and focuses it', async ({ page, api }) => {
        await page.setViewportSize({ width: 1280, height: 320 });
        await openIssue(page, api);
        const plan = page.locator(`#entry-${PLAN_REVISION}`);
        await expect(plan).not.toBeInViewport();

        await page.getByTestId('issue-current').getByRole('link', { name: 'Plan' }).click();

        await expect(page).toHaveURL(`/issues/${ISSUE_KEY}#entry-${PLAN_REVISION}`);
        await expect(plan).toBeInViewport({ ratio: 0.5 });
        await expect(plan).toBeFocused();
    });

    test('A replaced comment is closed', async ({ page, api }) => {
        await openIssue(page, api);

        await expect(replacedEntries(page, 'comment').getByText('goes, because')).toBeHidden();
    });

    test('A comment shows its label next to its actor, and the label Comment shows no badge', async ({ page, api }) => {
        await openIssue(page, api);
        const comments = page.locator('[data-testid="issue-entry"][data-kind="comment"][data-replaced="false"]');

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
        await expect(entries).toHaveText([/Decision.*Replaced/, /Decision.*InvoiceTotals::round\(\) stays as a deprecated wrapper/]);

        await page.getByRole('button', { name: 'Label: Decision' }).click();
        await page.getByRole('menuitemradio', { name: 'Papercut' }).click();

        await expect(page.getByTestId('issue-thread-empty')).toContainText('No comment has this label.');
        await page.getByRole('link', { name: 'Clear the filter' }).click();
        await expect(entries).toHaveCount(8);
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

        await expect(label.getByRole('option'), 'The server sets Question and Answer.').toHaveText(['Comment', 'Decision', 'Finding', 'Deviation', 'Scope creep', 'Papercut', 'Review']);
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

    test('The form of an open question comment sends I3 with replaces and each answer, and the timeline then shows the pairs and "Answered"', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        const asked = issues.agentAsks(ISSUE_KEY, 'Two choices before the test.', QUESTIONS);
        await page.goto(`/issues/${ISSUE_KEY}`);
        const form = page.getByRole('form', { name: /^Questions of claude-code laptop, / });
        await expect(form.locator('strong')).toHaveText('rounding');

        await form.getByRole('group', { name: QUESTIONS[0]?.question }).getByRole('button', { name: 'Yes', exact: true }).click();
        await form.getByRole('group', { name: QUESTIONS[1]?.question }).getByRole('textbox', { name: 'Other answer' }).fill('Money');
        await form.getByRole('button', { name: 'Send answers', exact: true }).click();

        await expect(form).toHaveCount(0);
        expect(api.bodies('POST', COMMENTS)).toEqual([
            {
                body: '',
                replaces: asked.id,
                questions: [
                    { question: QUESTIONS[0]?.question, answer: 'Yes' },
                    { question: QUESTIONS[1]?.question, answer: 'Money' },
                ],
            },
        ]);
        await expect(page.locator(`#entry-${asked.id}`)).toHaveText(/claude-code laptop.*Question commented.*Answered/);
        const answer = page.getByTestId('issue-entry').last();
        await expect(answer).toHaveText(/jan Answer commented/);
        await expect(answer.locator('[data-testid="comment-questions"] > li')).toHaveText([/Cover the shipping totals too\?\s*Yes/, /Which type holds the amounts\?\s*Money/]);
        await expect(answer, 'The form is gone, so the focus is on the answers.').toBeFocused();
        await expect(page.getByTestId('open-questions'), 'An answer comment has no form.').toHaveCount(0);
    });

    test('"Dismiss" sends I3 with replaces and dismiss, the form is gone, and the question comment shows "Dismissed"', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        const asked = issues.agentAsks(ISSUE_KEY, 'Two choices before the test.', QUESTIONS);
        await page.goto(`/issues/${ISSUE_KEY}`);
        const form = page.getByRole('form', { name: /^Questions of / });

        await form.getByRole('button', { name: 'Dismiss', exact: true }).click();

        await expect(form).toHaveCount(0);
        expect(api.bodies('POST', COMMENTS)).toEqual([{ body: '', replaces: asked.id, dismiss: true }]);
        await expect(page.locator(`#entry-${asked.id}`)).toHaveText(/claude-code laptop.*Question commented.*Dismissed/);
        const dismissal = page.getByTestId('issue-entry').last();
        await expect(dismissal).toHaveText(/jan Dismissal commented.*Dismissed\./);
        await expect(dismissal, 'The form is gone, so the focus is on the dismissal.').toBeFocused();
    });

    test('A stored question comment without questions shows no form', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        issues.agentAsks(ISSUE_KEY, 'Does a credit note round as its invoice did?', []);
        await page.goto(`/issues/${ISSUE_KEY}`);

        await expect(page.getByTestId('issue-entry').last()).toHaveText(/claude-code laptop.*Question commented.*Does a credit note round as its invoice did\?/);
        await expect(page.getByTestId('open-questions')).toHaveCount(0);
    });

    test('Typed answers stay after a reload until "Send answers" sends them', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        issues.agentAsks(ISSUE_KEY, 'Two choices before the test.', QUESTIONS);
        await page.goto(`/issues/${ISSUE_KEY}`);
        const form = page.getByRole('form', { name: /^Questions of / });
        const yes = form.getByRole('group', { name: QUESTIONS[0]?.question }).getByRole('button', { name: 'Yes', exact: true });
        const other = form.getByRole('group', { name: QUESTIONS[1]?.question }).getByRole('textbox', { name: 'Other answer' });

        await yes.click();
        await other.fill('Money');
        await page.reload();

        await expect(yes).toHaveAttribute('aria-pressed', 'true');
        await expect(other).toHaveValue('Money');
        await form.getByRole('button', { name: 'Send answers', exact: true }).click();
        await expect(form).toHaveCount(0);
        expect(await page.evaluate(() => Object.keys(sessionStorage).filter((key) => key.includes(':answers:'))), 'A sent answer leaves no draft.').toEqual([]);
    });

    test('A question comment that the agent replaced shows the typed answers and a link to the newer version, and "Discard" removes them', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        const asked = issues.agentAsks(ISSUE_KEY, 'Two choices before the test.', QUESTIONS);
        await page.goto(`/issues/${ISSUE_KEY}`);
        await page.getByRole('form', { name: /^Questions of / }).getByRole('button', { name: 'Yes', exact: true }).first().click();
        const newer = issues.agentAsks(ISSUE_KEY, 'One choice is left.', QUESTIONS.slice(1), asked.id);
        await page.reload();
        const notice = page.getByRole('region', { name: 'The agent replaced these questions.' });

        await expect(notice.locator('[data-testid="comment-questions"] > li')).toHaveText(['Cover the shipping totals too?Yes', 'Which type holds the amounts?']);
        await expect(notice.getByRole('link', { name: 'Newer version' })).toHaveAttribute('href', `/issues/${ISSUE_KEY}#entry-${newer.id}`);
        await notice.getByRole('button', { name: 'Discard' }).click();
        await expect(notice).toHaveCount(0);
        await page.reload();
        await expect(page.getByRole('form', { name: /^Questions of / })).toBeVisible();
        await expect(notice).toHaveCount(0);
    });

    test('A send after the agent replaced the question comment shows the typed answers', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        const asked = issues.agentAsks(ISSUE_KEY, 'Two choices before the test.', QUESTIONS);
        await page.goto(`/issues/${ISSUE_KEY}`);
        const form = page.getByRole('form', { name: /^Questions of / });
        await form.getByRole('group', { name: QUESTIONS[0]?.question }).getByRole('button', { name: 'Yes', exact: true }).click();
        await form.getByRole('group', { name: QUESTIONS[1]?.question }).getByRole('textbox', { name: 'Other answer' }).fill('Money');
        await firstLiveReload(page, api);
        issues.agentAsks(ISSUE_KEY, 'One choice is left.', QUESTIONS.slice(1), asked.id);

        await form.getByRole('button', { name: 'Send answers', exact: true }).click();

        await expect(page.getByRole('region', { name: 'The agent replaced these questions.' }).locator('[data-testid="comment-questions"] > li')).toHaveText([
            'Cover the shipping totals too?Yes',
            'Which type holds the amounts?Money',
        ]);
        expect(api.bodies('POST', COMMENTS)).toHaveLength(1);
    });

    test('An issue key in a comment opens its issue without a page load, and a code block and a Markdown link get no key link', async ({ page, api }) => {
        const issues = await openIssue(page, api);
        issues.add(issueDetail({ key: 'BILL-9', title: 'Round shipping totals per line' }));

        await page.getByRole('textbox', { name: 'Comment' }).fill('BILL-9 is first, as `BILL-9` says and [BILL-9](https://tracker.example/9) said.\n\n```\ngit log --grep BILL-9\n```');
        await page.getByRole('button', { name: 'Comment', exact: true }).click();
        const comment = page.getByTestId('issue-entry').last();
        const links = comment.getByRole('link', { name: 'BILL-9', exact: true });

        await expect(links).toHaveCount(3);
        expect(await links.evaluateAll((all) => all.map((link) => link.getAttribute('href')))).toEqual(['/issues/BILL-9', '/issues/BILL-9', 'https://tracker.example/9']);
        await expect(comment.locator('code > a')).toHaveCount(1);
        await expect(comment.locator('pre')).toHaveText(/git log --grep BILL-9/);
        const pageLoads = api.callsTo('GET', '/api/v1/me').length;
        await links.first().click();

        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Round shipping totals per line BILL-9');
        expect(api.callsTo('GET', '/api/v1/me'), 'A page load reads A1 again.').toHaveLength(pageLoads);
    });

    test('An issue key in a question line is a link, in the timeline and in the form', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        issues.agentAsks(ISSUE_KEY, 'One choice.', [{ question: 'Round as BILL-9 does?', detail: null, answer: null }]);
        await page.goto(`/issues/${ISSUE_KEY}`);

        for (const place of [page.getByTestId('issue-entry').last(), page.getByRole('form', { name: /^Questions of / })]) {
            await expect(place.getByRole('link', { name: 'BILL-9' })).toHaveAttribute('href', '/issues/BILL-9');
        }
    });

    test('An issue key in the title of an issue is a link, and a key of an unknown project is text', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail({ title: 'Round as BILL-9 does, in UTF-8' }));
        await page.goto(`/issues/${ISSUE_KEY}`);

        await expect(page.getByRole('heading', { level: 1 }).getByRole('link')).toHaveText(['BILL-9']);
        await expect(page.getByRole('heading', { level: 1 }).getByRole('link')).toHaveAttribute('href', '/issues/BILL-9');
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

        await expect(page.getByRole('button', { name: 'Write Plan' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Approve plan' })).toHaveCount(0);
    });

    test('A refused approval shows the message of the server and the newest plan, which the next click approves after "Read the new plan"', async ({ page, api }) => {
        const issues = await openIssue(page, api);
        await firstLiveReload(page, api);
        issues.changePlan(ISSUE_KEY, 'Round once per invoice line.');
        const approve = page.getByRole('button', { name: 'Approve plan' });

        await approve.click();

        await expect(section(page, 'plan')).toContainText('Round once per invoice line.');
        await expect(page.getByTestId('action-error')).toContainText('The plan changed after this revision. Read the plan again, and approve its newest revision.');
        await page.getByRole('button', { name: 'Read the new plan' }).click();
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

    test('The project settings show the guidelines below the mode "Per issue" only, and save them with the mode', async ({ page, api }) => {
        new FakeRepositories(api);
        const issues = new FakeIssues(api);
        issues.projects = [project({ repositories: [] })];
        const guidelines = '- A fix of a typo: `self`.';
        await page.goto('/projects/BILL/settings');
        const field = page.getByRole('textbox', { name: 'Guidelines for the agent' });
        await expect(field).toBeHidden();

        await page.getByRole('radio', { name: 'Per issue' }).check();
        await field.fill(guidelines);
        await page.getByRole('button', { name: 'Save' }).click();

        await expect(page.getByTestId('settings-saved')).toBeVisible();
        expect(api.bodies('PATCH', '/api/v1/projects/:projectKey')).toEqual([{ name: 'Billing', approvalMode: 'by_issue', approvalGuidelines: guidelines, repositoryIds: [] }]);
        expect(issues.projects[0]).toEqual(project({ approvalMode: 'by_issue', approvalGuidelines: guidelines, repositories: [] }));
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

    test('"Write" of a section without text saves it as a new entry, and Save is off while the text is empty', async ({ page, api }) => {
        await openIssue(page, api);

        await page.getByRole('button', { name: 'Write Result' }).click();
        const field = page.getByRole('textbox', { name: 'Result' });
        await expect(page.getByRole('button', { name: 'Save' })).toBeDisabled();
        await field.fill('Added `TaxRounding::perInvoice()` in a1b2c3d.');
        await page.getByRole('button', { name: 'Save' }).click();

        await expect(section(page, 'result').locator('code')).toHaveText('TaxRounding::perInvoice()');
        await expect(field).toHaveCount(0);
        await expect(page.getByTestId('issue-entry').last()).toHaveText(/jan Result changed the result/);
        await expect(page.getByTestId('issue-current').getByRole('link', { name: 'Result' })).toBeVisible();
        expect(api.bodies('PATCH', ISSUE)).toEqual([{ result: 'Added `TaxRounding::perInvoice()` in a1b2c3d.' }]);
    });

    test('"Revise" sends the changed text, which becomes a new version at the end, and the version before it is replaced', async ({ page, api }) => {
        await openIssue(page, api);

        await page.getByRole('button', { name: 'Revise Plan' }).click();
        const field = page.getByRole('textbox', { name: 'Plan' });
        await expect(field).toHaveValue(/^1\. Add `TaxRounding::perInvoice\(\)`/);
        // The plan text is not on the page while the editor is open, so nothing is there to approve.
        await expect(page.getByRole('button', { name: 'Approve plan' })).toHaveCount(0);
        await field.fill('Round once per invoice line.');
        await page.getByRole('button', { name: 'Save' }).click();

        await expect(page.getByTestId('issue-entry').last()).toHaveText(/jan Plan changed the plan.*Round once per invoice line\./);
        await expect(page.getByTestId('plan-changed'), 'The plan of the user needs no "Read the new plan".').toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Approve plan' })).toBeEnabled();
        await expect(replacedEntries(page, 'section_changed')).toHaveCount(2);
        await expect(replacedEntries(page, 'plan_approved')).toHaveCount(1);
        expect(api.bodies('PATCH', ISSUE)).toEqual([{ plan: 'Round once per invoice line.' }]);
    });

    test('A refused section keeps its text in the editor, also after a reload', async ({ page, api }) => {
        await openIssue(page, api);
        const message = 'This value is too long. It should have 65536 characters or less.';
        api.on('PATCH', ISSUE, () => apiError(422, 'invalid_input', `Invalid input. plan: ${message}`, { violations: [{ field: 'plan', message }] }));
        const plan = section(page, 'plan');
        const field = plan.getByRole('textbox', { name: 'Plan' });

        await page.getByRole('button', { name: 'Revise Plan' }).click();
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

    test('"Won\'t do" on an issue with an open question comment dismisses it and does not move the focus to the dismissal', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        issues.agentAsks(ISSUE_KEY, 'Two choices before the test.', QUESTIONS);
        await page.goto(`/issues/${ISSUE_KEY}`);
        await expect(page.getByTestId('open-questions')).toBeVisible();

        await chooseStatus(page, "Won't do");
        const dialog = page.getByRole('dialog');
        await dialog.getByRole('textbox', { name: 'Note' }).fill('BILL-9 covers it.');
        await dialog.getByRole('button', { name: "Set won't do" }).click();

        await expect(page.getByTestId('open-questions')).toHaveCount(0);
        const dismissal = page.getByTestId('issue-entry').last();
        await expect(dismissal).toHaveText(/jan Dismissal commented.*Dismissed: the issue is won't do\./);
        await expect(dismissal).not.toBeFocused();
        await expect(page).not.toHaveURL(/#entry-/);
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
