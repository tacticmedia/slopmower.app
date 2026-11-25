import type { CommentLabel, Question } from '../../assets/api/types/common';
import type { Issue, IssueDetail, IssueEntry } from '../../assets/api/types/issue';
import { issueDetail, project } from '../ui/support/builders';
import { FakeIssues } from '../ui/support/fakeIssues';
import { FakeRepositories } from '../ui/support/fakeRepositories';
import { test } from './support/test';

/** A comment of the agent of `issue.detail.json`. I5 sorts by `id`, so the IDs rise with the times. */
function comment(id: string, label: CommentLabel, body: string, createdAt: string, questions: Question[] = []): IssueEntry {
    return { ...(issueDetail().thread.at(-1) as IssueEntry), id, label, body, createdAt, questions };
}

function issue(overrides: Partial<Issue>, ...thread: IssueEntry[]): IssueDetail {
    return { ...issueDetail(overrides), thread };
}

test.describe('Issues', () => {
    test('The projects, the issues of a project, an issue with its timeline, its questions with their answers, the comments of a project, and its dependency graph', async ({ page, api, captureScreen }) => {
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
                comment('01K7F3F0G9H0J1K2M3N4P5Q6R7', 'question', 'Two choices before the plan.', '2026-10-02T09:16:40Z', [
                    { question: 'Does a credit note round with the rule of today?', detail: 'Its invoice rounded each line. `TaxRounding::perInvoice()` rounds once.', answer: null },
                    { question: 'Which invoices get a corrected credit note?', detail: null, answer: null },
                ]),
            ),
        );
        issues.add(issue({ key: 'BILL-13', title: 'Show the rounding mode on the invoice PDF', status: 'draft', updatedAt: '2026-10-02T09:04:18Z' }));
        issues.add(
            issue(
                { key: 'BILL-9', title: 'Round shipping totals per line', status: 'done', updatedAt: '2026-10-02T09:03:30Z' },
                comment('01K7F3A2B3C4D5E6F7G8H9J0K1', 'papercut', '`tests/Billing` needs the `billing_test` database, and `README.md` does not name it.', '2026-10-02T09:02:10Z'),
            ),
        );
        issues.add(issue({ key: 'BILL-15', title: 'Name the rounding mode in the README', status: 'in_review', updatedAt: '2026-10-02T09:18:22Z' }));
        issues.block('BILL-13', 'BILL-12');
        issues.block('BILL-14', 'BILL-12');

        await page.goto('/projects');
        await captureScreen('Projects', page.getByTestId('projects').locator('table'));

        await page.getByRole('link', { name: 'BILL', exact: true }).click();
        await captureScreen('Issues of a project', page.getByTestId('issue-list'));

        await page.getByRole('link', { name: 'Round invoice totals once per invoice' }).click();
        await captureScreen('Issue', page.getByTestId('issue-thread'));

        await page.locator('[data-testid="issue-entry"][data-replaced="true"] summary').first().click({ position: { x: 8, y: 8 } });
        await captureScreen('Issue replaced version', page.getByText('Round the total in'));

        await page.goto('/issues/BILL-14');
        const questions = page.getByTestId('open-questions');
        await captureScreen('Issue open questions', questions);
        await questions.getByRole('button', { name: 'Yes', exact: true }).first().click();
        await questions.getByRole('textbox', { name: 'Other answer' }).last().fill('Those of this year.');
        await questions.getByRole('button', { name: 'Send answers', exact: true }).click();
        await captureScreen('Issue answered questions', page.getByTestId('answer').last());

        await page.goto('/projects/BILL/comments');
        await captureScreen('Comments of a project', page.getByTestId('comment-list'));

        await page.goto('/projects/BILL/graph');
        await captureScreen('Dependency graph of a project', page.getByTestId('issue-graph'));

        const question = { question: 'Name the mode in the README of the package too?', detail: null, answer: null };
        const asked = issues.agentAsks('BILL-15', 'One choice before the review.', [question]);
        await page.goto('/issues/BILL-15');
        await page.getByRole('button', { name: 'Yes', exact: true }).click();
        issues.agentAsks('BILL-15', 'One choice before the review, with the package named.', [{ ...question, question: 'Name the mode in the README of `billing-core` too?' }], asked.id);
        await page.reload();
        await captureScreen('Issue replaced questions', page.getByRole('region', { name: 'The agent replaced these questions.' }));
    });

    test('A new project, the settings of a project, a new issue, and the edits and the status of an issue', async ({ page, api, captureScreen }) => {
        const repositories = new FakeRepositories(api);
        for (const name of ['billing', 'ledger', 'handbook']) {
            repositories.addLocal(name);
        }
        const issues = new FakeIssues(api, () => repositories.repositories);
        issues.projects = [project({ repositories: ['billing'] }), project({ key: 'DOC', name: 'Documentation', approvalMode: 'user', repositories: ['handbook'] })];
        issues.add(issueDetail());
        const dialog = page.getByRole('dialog');

        await page.goto('/projects');
        await page.getByRole('button', { name: 'New project' }).click();
        await dialog.getByRole('textbox', { name: 'Key' }).fill('BILL');
        await dialog.getByRole('textbox', { name: 'Name' }).fill('Billing of the shop');
        await dialog.getByRole('button', { name: 'Create project' }).click();
        await captureScreen('New project', dialog.getByText('The project BILL exists already.'));

        await page.goto('/projects/BILL');
        await page.getByRole('link', { name: 'Project settings' }).click();
        await captureScreen('Project settings', page.getByRole('button', { name: 'Save' }));

        await page.goto('/projects/BILL/issues/new');
        await page.getByRole('textbox', { name: 'Title' }).fill('Show the rounding mode on the invoice PDF');
        await page.getByRole('textbox', { name: 'Problem' }).fill('The PDF does not say how the total rounds.\n\nDone when the footer of the PDF names the mode.');
        await captureScreen('New issue', page.getByRole('button', { name: 'Create issue' }));
        await page.getByRole('button', { name: 'Create issue' }).click();
        await page.waitForURL('/issues/BILL-13');

        await page.goto('/issues/BILL-12');
        await page.getByRole('button', { name: 'Write Result' }).click();
        await page.getByRole('textbox', { name: 'Result' }).fill('Added `TaxRounding::perInvoice()` in a1b2c3d. No deviation.');
        await captureScreen('Issue section editor', page.getByRole('textbox', { name: 'Result' }));
        await page.getByRole('button', { name: 'Cancel' }).click();

        await page.getByRole('button', { name: 'Set status' }).click();
        await captureScreen('Issue status menu', page.getByRole('menuitem', { name: "Won't do" }));
        await page.getByRole('menuitem', { name: "Won't do" }).click();
        await dialog.getByRole('textbox', { name: 'Note' }).fill('BILL-9 rounds the shipping totals, and the invoice totals follow them.');
        await captureScreen("Issue won't do", dialog.getByRole('button', { name: "Set won't do" }));
        await dialog.getByRole('button', { name: 'Cancel' }).click();

        await page.getByRole('button', { name: 'Set status' }).click();
        await page.getByRole('menuitem', { name: 'Done' }).click();
        await captureScreen('Issue status refused', page.getByTestId('status-error'));
    });
});
