import type { Locator, Page } from '@playwright/test';
import type { Question } from '../../assets/api/types/common';
import type { Issue, IssueDetail, IssueEntry } from '../../assets/api/types/issue';
import { issueDetail, newId, now } from './support/builders';
import { FakeIssues } from './support/fakeIssues';
import { expect, test } from './support/test';

const ISSUE_KEY = 'BILL-12';
const COMMENTS = '/api/v1/issues/:issueKey/comments';
const QUESTIONS: Question[] = [
    { question: 'Cover the shipping totals too?', detail: null, answer: null },
    { question: 'Which type holds the amounts?', detail: null, answer: null },
];
const ANSWERS = [
    { question: QUESTIONS[0]?.question, answer: 'Yes' },
    { question: QUESTIONS[1]?.question, answer: 'Money' },
];

const CHANGES = 'Round the shipping totals in BILL-9, not here.';
const RESULT = 'Added `TaxRounding::perInvoice()` on the branch `bill-12-round-once`.';

function section(page: Page, name: string): Locator {
    return page.locator(`[data-testid="issue-section"][data-section="${name}"]`);
}

/** `BILL-12` of `issue.detail.json` with a current version of the result. */
function withResult(overrides: Partial<Issue>): IssueDetail {
    const detail = issueDetail({ result: RESULT, ...overrides });

    return { ...detail, thread: [...detail.thread, { ...(detail.thread[0] as IssueEntry), id: newId(), createdAt: now(), section: 'result', body: RESULT }] };
}

/** Answers "Yes" to the first question and "Money" to the second one. */
async function answer(form: Locator): Promise<void> {
    await form.getByRole('group', { name: QUESTIONS[0]?.question }).getByRole('button', { name: 'Yes', exact: true }).click();
    await form.getByRole('group', { name: QUESTIONS[1]?.question }).getByRole('textbox', { name: 'Other answer' }).fill('Money');
}

test.describe('Issue hand-back', () => {
    test('"Send answers and hand over" sends the answers with waitingFor, and the issue then waits for the agent', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail({ waitingFor: 'requester' }));
        const asked = issues.agentAsks(ISSUE_KEY, 'Two choices before the test.', QUESTIONS);
        await page.goto(`/issues/${ISSUE_KEY}`);
        const form = page.getByRole('form', { name: /^Questions of / });
        await answer(form);

        await form.getByRole('button', { name: 'Send answers and hand over' }).click();

        await expect(form).toHaveCount(0);
        expect(api.bodies('POST', COMMENTS)).toEqual([{ body: '', replaces: asked.id, questions: ANSWERS, waitingFor: 'assignee' }]);
        await expect(page.getByTestId('waiting-label').first()).toHaveText('Waiting for the agent');
        await expect(page.getByTestId('issue-entry').last()).toHaveText(/jan handed the issue to the agent/);
    });

    test('Enter in an answer sends the answers without a hand-over', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail({ waitingFor: 'requester' }));
        const asked = issues.agentAsks(ISSUE_KEY, 'Two choices before the test.', QUESTIONS);
        await page.goto(`/issues/${ISSUE_KEY}`);
        const form = page.getByRole('form', { name: /^Questions of / });
        await answer(form);

        await form.getByRole('group', { name: QUESTIONS[1]?.question }).getByRole('textbox', { name: 'Other answer' }).press('Enter');

        await expect(form).toHaveCount(0);
        expect(api.bodies('POST', COMMENTS)).toEqual([{ body: '', replaces: asked.id, questions: ANSWERS }]);
        await expect(page.getByTestId('waiting-label').first()).toHaveText('Waiting for jan');
    });

    test('On an issue that waits for the agent, the answer form has only "Send answers"', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        issues.agentAsks(ISSUE_KEY, 'Two choices before the test.', QUESTIONS);
        await page.goto(`/issues/${ISSUE_KEY}`);
        const form = page.getByRole('form', { name: /^Questions of / });

        await expect(form.getByRole('button', { name: 'Send answers', exact: true })).toHaveAttribute('data-variant', 'primary');
        await expect(form.getByRole('button', { name: 'Send answers and hand over' })).toHaveCount(0);
    });

    test('"Request changes" on the plan sends the text with the label review and waitingFor assignee, and the issue then waits for the agent', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail({ waitingFor: 'requester' }));
        await page.goto(`/issues/${ISSUE_KEY}`);
        const plan = section(page, 'plan');

        await plan.getByRole('button', { name: 'Request changes' }).click();
        await plan.getByRole('textbox', { name: 'Changes to the plan' }).fill(CHANGES);
        await plan.getByRole('button', { name: 'Request changes' }).click();

        await expect(plan.getByRole('textbox', { name: 'Changes to the plan' })).toHaveCount(0);
        expect(api.bodies('POST', COMMENTS)).toEqual([{ body: CHANGES, label: 'review', waitingFor: 'assignee' }]);
        await expect(page.getByTestId('waiting-label').first()).toHaveText('Waiting for the agent');
    });

    test('The plan has no "Request changes" while the issue waits for the agent, and in backlog, in review, or approved', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(issueDetail());
        issues.add(issueDetail({ key: 'BILL-13', status: 'backlog', waitingFor: null }));
        issues.add(withResult({ key: 'BILL-14', status: 'in_review', waitingFor: 'requester' }));
        issues.add(withResult({ key: 'BILL-15', status: 'approved', waitingFor: 'requester' }));

        for (const key of [ISSUE_KEY, 'BILL-13', 'BILL-14', 'BILL-15']) {
            await page.goto(`/issues/${key}`);
            await expect(section(page, 'plan').getByRole('button', { name: 'Approve plan' })).toBeVisible();
            await expect(section(page, 'plan').getByRole('button', { name: 'Request changes' }), key).toHaveCount(0);
        }
    });

    test('"Request changes" on the result of an issue in review sends the same body, and the result of an issue in progress has none', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        issues.add(withResult({ status: 'in_review', waitingFor: 'requester' }));
        issues.add(withResult({ key: 'BILL-13', waitingFor: 'requester' }));
        await page.goto(`/issues/${ISSUE_KEY}`);
        const result = section(page, 'result');

        await result.getByRole('button', { name: 'Request changes' }).click();
        await result.getByRole('textbox', { name: 'Changes to the result' }).fill(CHANGES);
        await result.getByRole('button', { name: 'Request changes' }).click();

        await expect(page.getByTestId('waiting-label').first()).toHaveText('Waiting for the agent');
        expect(api.bodies('POST', COMMENTS)).toEqual([{ body: CHANGES, label: 'review', waitingFor: 'assignee' }]);
        await page.goto('/issues/BILL-13');
        await expect(section(page, 'result')).toBeVisible();
        await expect(section(page, 'result').getByRole('button', { name: 'Request changes' })).toHaveCount(0);
    });
});
