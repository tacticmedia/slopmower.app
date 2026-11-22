import type { CommentLabel } from '../../assets/api/types/common';
import type { Issue, IssueDetail, IssueEntry } from '../../assets/api/types/issue';
import { issueDetail, project } from '../ui/support/builders';
import { FakeIssues } from '../ui/support/fakeIssues';
import { test } from './support/test';

/** A comment of the agent of `issue.detail.json`. I5 sorts by `id`, so the IDs rise with the times. */
function comment(id: string, label: CommentLabel, body: string, createdAt: string): IssueEntry {
    return { ...(issueDetail().thread.at(-1) as IssueEntry), id, label, body, createdAt };
}

function issue(overrides: Partial<Issue>, ...thread: IssueEntry[]): IssueDetail {
    return { ...issueDetail(overrides), thread };
}

test.describe('Issues', () => {
    test('The projects, the issues of a project, an issue with its labelled thread, and the comments of a project', async ({ page, api, captureScreen }) => {
        const issues = new FakeIssues(api);
        issues.projects = [project(), project({ key: 'DOC', name: 'Documentation', approvalMode: 'user', repositories: [] })];
        issues.add(
            issue(
                {},
                ...issueDetail().thread,
                comment('01K7F3G0G9H0J1K2M3N4P5Q6R7', 'finding', '`InvoiceTotalsTest` has no case with more than one line. Source: `tests/Billing/InvoiceTotalsTest.php`.', '2026-10-02T09:22:10Z'),
                comment('01K7F3H0G9H0J1K2M3N4P5Q6R7', 'deviation', 'The test is in `TaxRoundingTest`, not in `InvoiceTotalsTest`, because the rule is in `TaxRounding`.', '2026-10-02T09:24:31Z'),
                comment('01K7F3J0G9H0J1K2M3N4P5Q6R7', 'scope_creep', 'Credit notes round each line too. Left out: BILL-14.', '2026-10-02T09:25:02Z'),
                comment('01K7F3K0G9H0J1K2M3N4P5Q6R7', 'review', 'The result matches the plan. One finding: the README still names `round()`.', '2026-10-02T09:31:45Z'),
            ),
        );
        issues.add(
            issue(
                { key: 'BILL-14', title: 'Round the totals of credit notes', status: 'awaiting_user_input', updatedAt: '2026-10-02T09:16:40Z' },
                comment('01K7F3F0G9H0J1K2M3N4P5Q6R7', 'question', 'Does a credit note round as its invoice did, or with the rule of today?', '2026-10-02T09:16:40Z'),
            ),
        );
        issues.add(issue({ key: 'BILL-13', title: 'Show the rounding mode on the invoice PDF', status: 'draft', updatedAt: '2026-10-02T09:04:18Z' }));
        issues.add(
            issue(
                { key: 'BILL-9', title: 'Round shipping totals per line', status: 'done', updatedAt: '2026-10-02T09:03:30Z' },
                comment('01K7F3A2B3C4D5E6F7G8H9J0K1', 'papercut', '`tests/Billing` needs the `billing_test` database, and `README.md` does not name it.', '2026-10-02T09:02:10Z'),
            ),
        );

        await page.goto('/projects');
        await captureScreen('Projects', page.getByTestId('projects').locator('table'));

        await page.getByRole('link', { name: 'BILL', exact: true }).click();
        await captureScreen('Issues of a project', page.getByTestId('issue-list'));

        await page.getByRole('link', { name: 'Round invoice totals once per invoice' }).click();
        await captureScreen('Issue', page.getByTestId('issue-thread'));

        await page.goto('/projects/BILL/comments');
        await captureScreen('Comments of a project', page.getByTestId('comment-list'));
    });
});
