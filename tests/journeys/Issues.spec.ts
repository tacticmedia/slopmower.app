import { issueDetail, project } from '../ui/support/builders';
import { FakeIssues } from '../ui/support/fakeIssues';
import { test } from './support/test';

test.describe('Issues', () => {
    test('The projects, the issues of a project, and an issue with its thread', async ({ page, api, captureScreen }) => {
        const issues = new FakeIssues(api);
        issues.projects = [project(), project({ key: 'DOC', name: 'Documentation', approvalMode: 'user', repositories: [] })];
        issues.add(issueDetail());
        issues.add(issueDetail({ key: 'BILL-14', title: 'Round the totals of credit notes', status: 'awaiting_user_input', updatedAt: '2026-10-02T09:16:40Z' }));
        issues.add(issueDetail({ key: 'BILL-13', title: 'Show the rounding mode on the invoice PDF', status: 'draft', updatedAt: '2026-10-02T09:04:18Z' }));
        issues.add(issueDetail({ key: 'BILL-9', title: 'Round shipping totals per line', status: 'done', updatedAt: '2026-10-02T09:03:30Z' }));

        await page.goto('/projects');
        await captureScreen('Projects', page.getByTestId('projects').locator('table'));

        await page.getByRole('link', { name: 'BILL', exact: true }).click();
        await captureScreen('Issues of a project', page.getByTestId('issue-list'));

        await page.getByRole('link', { name: 'Round invoice totals once per invoice' }).click();
        await captureScreen('Issue', page.getByTestId('issue-thread'));
    });
});
