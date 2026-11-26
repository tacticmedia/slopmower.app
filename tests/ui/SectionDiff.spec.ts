import { issueDetail } from './support/builders';
import { FakeIssues } from './support/fakeIssues';
import { expect, test } from './support/test';

const ISSUE_KEY = 'BILL-12';

test.describe('SectionDiff', () => {
    test('"Show changes" shows the changed words against the earlier version, a second click shows the text, and a section without an earlier version has no button', async ({ page, api }) => {
        const issues = new FakeIssues(api);
        const { plan } = issues.add(issueDetail()).issue;
        issues.changePlan(ISSUE_KEY, (plan ?? '').replace('three lines', 'four lines'));
        await page.goto(`/issues/${ISSUE_KEY}`);
        const section = page.locator('[data-testid="issue-section"][data-section="plan"]');
        const show = page.getByRole('button', { name: 'Show changes of the plan' });
        await expect(page.getByRole('button', { name: 'Revise Problem' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Show changes of the problem' })).toHaveCount(0);

        await show.click();
        await expect(section.locator('[data-line]').first()).toBeAttached();
        await expect(section.locator('[data-diff-span]')).toHaveText(['three', 'four']);
        await expect(show).toHaveAttribute('aria-pressed', 'true');

        await show.click();
        await expect(section.locator('[data-line]')).toHaveCount(0);
        await expect(section).toContainText('Test: a case with four lines of 0.335');
    });

    test('"Show changes" of the plan notice reads the new plan and shows its changes', async ({ page, api, live }) => {
        const issues = new FakeIssues(api);
        const { plan } = issues.add(issueDetail({ waitingFor: 'requester' })).issue;
        await page.goto(`/issues/${ISSUE_KEY}`);
        await expect(page.locator('[data-live="connected"]')).toBeAttached();

        issues.changePlan(ISSUE_KEY, (plan ?? '').replace('three lines', 'four lines'));
        await live.push({ type: 'issue', issueKey: ISSUE_KEY });
        const notice = page.getByRole('region', { name: 'The plan changed while this page was open.' });
        await notice.getByRole('button', { name: 'Show changes' }).click();

        await expect(notice).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Approve plan' })).toBeFocused();
        await expect(page.locator('[data-testid="issue-section"][data-section="plan"] [data-diff-span]')).toHaveText(['three', 'four']);
    });
});
