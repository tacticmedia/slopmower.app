import type { Page } from '@playwright/test';
import type { ProposeSolutionInput } from '../../assets/api/types/inputs';
import { addedFile, commitDiff, commitLink, fixture, modifiedFile, sha1 } from '../ui/support/builders';
import { FakeReview } from '../ui/support/fakeReview';
import { SMALL_FILE, SMALL_FILE_TEXT, SYMBOL_FILE } from '../ui/support/files';
import { apiError, json, type MockApi, text } from '../ui/support/mockApi';
import { DETAIL, DETAIL_NEXT_STEP, DETAIL_STATUS, DETAIL_THREAD, openRequest, PLAN_STATE } from '../ui/support/requestScreens';
import { expect, test } from './support/test';

const { plan: PLAN, diff: DIFF } = fixture<Required<ProposeSolutionInput>>('inputs.propose-solution.json');

const PULL_REQUEST = 'https://github.com/acme/billing/pull/42';

const SUGGESTION_BODY = 'Use `PHP_ROUND_HALF_UP`. `PHP_ROUND_HALF_EVEN` rounds a tax of 12.5 cents to 12 cents, and invoices round a half cent up.';

const DIFF_LINE = 'diffs-container [data-line-type]';

/** The agent's questions and the user's answers of a long conversation. */
const DISCUSSION: readonly [string, string][] = [
    ['Should every PHP file declare strict types, or only the files under `src/`?', 'Every PHP file, also the tests and the configuration.'],
    ['`config/bundles.php` returns an array only. Does it need the declaration too?', 'Yes, the rule has no exceptions.'],
    ['Three test fixtures compare floats with `==`. Strict types change nothing there. Should I leave them?', 'Leave them. Another request covers the comparisons.'],
    ['Should I add a PHP-CS-Fixer rule so that new files get the declaration?', 'Yes, `declare_strict_types` with the risky rules allowed.'],
    ['The rule also rewrites `bin/console`. Is that fine?', 'Fine.'],
    ['One commit for all files, or one per directory?', 'One commit.'],
];

const EXTRACTED = SMALL_FILE_TEXT.replace('intdiv($net * $taxBasisPoints, 10000)', '$this->tax($net, $taxBasisPoints)');

const CHANGELOG = '# Changelog\n\n## 2.4.0\n\n- Extract the tax calculation.\n';

/** Links one commit that changes the anchored file and adds a changelog, with Q9 and R7 for the Changes tab. */
function linkExtractCommit(api: MockApi, review: FakeReview, requestId: string): void {
    const commit = commitLink(sha1('Extract tax()'), 'Extract tax()');
    review.agentLinks(requestId, commit);
    const diff = commitDiff(review.find(requestId), commit, [addedFile('CHANGELOG.md', CHANGELOG), modifiedFile(SMALL_FILE, SMALL_FILE_TEXT, EXTRACTED, true)]);
    const blobs = new Map([SMALL_FILE_TEXT, EXTRACTED, CHANGELOG].map((content) => [sha1(content), content]));
    api.on('GET', '/api/v1/requests/:requestId/commits/:commitSha', ({ params }) => (params.requestId === requestId ? json('commit-diff.schema.json', diff) : undefined));
    api.on('GET', '/api/v1/repositories/:repositoryId/blobs/:blobSha', ({ params }) => {
        const blob = blobs.get(params.blobSha ?? '');

        return undefined === blob ? apiError(404, 'not_found', `No blob ${params.blobSha} exists.`) : text(blob);
    });
}

function status(page: Page, label: string) {
    return page.locator(DETAIL_STATUS).filter({ hasText: label });
}

test.describe('RequestStages', () => {
    test('A request goes from a suggestion to the work of the agent', async ({ page, api, captureScreen }) => {
        const review = new FakeReview(api);

        const suggested = review.agentSuggests({ kind: 'line', path: SMALL_FILE, range: { startLine: 11, endLine: 11 } }, 'Round half cents up', SUGGESTION_BODY);
        await openRequest(page, suggested.id);
        await captureScreen('Suggestion waiting for you', page.locator(`${DETAIL} [data-testid="suggestion-actions"]`));

        const planned = review.seedGeneral('Add a CSV export of the invoice totals.', true, 'CSV export of the invoice totals');
        await openRequest(page, planned.id);
        await captureScreen('Open with a plan required', page.locator(PLAN_STATE));

        const asked = review.seedLines(SYMBOL_FILE, 19, 22, 'Add a test for an amount of zero.');
        review.agentClaims(asked.id);
        review.agentAsks(asked.id, 'Should the test cover a negative amount too?');
        await openRequest(page, asked.id);
        await captureScreen('Agent question', page.locator(DETAIL_THREAD).getByText('Should the test cover a negative amount too?'));

        review.agentClaims(planned.id);
        const { eventId } = review.agentProposes(planned.id, PLAN, DIFF);
        await openRequest(page, planned.id);
        const files = page.locator(`${DETAIL_THREAD} [data-testid="proposal"][data-event-id="${eventId}"] [data-testid="proposal-diff-file"]`);
        for (const file of await files.all()) {
            await expect(file.locator(DIFF_LINE).first()).toBeAttached();
        }
        await captureScreen('Proposal with a diff', files.last());

        const claimed = review.seedLines(SMALL_FILE, 9, 12, 'Move the rounding of `total()` into its own method.', 'Extract the tax calculation');
        review.agentClaims(claimed.id);
        review.command(claimed.id, 'comments', { body: 'Keep the method private.' });
        await openRequest(page, claimed.id);
        await captureScreen('In progress', page.locator(`${DETAIL} [data-testid="claimed-by"]`));

        await page.locator(DETAIL).getByRole('button', { name: 'Edit', exact: true }).click();
        await captureScreen('Title editor', page.locator(`${DETAIL} [data-testid="title-editor"]`));

        const orphaned = review.seedLines(SYMBOL_FILE, 19, 22, 'Rename ceil() to roundUp().');
        review.markOrphaned(orphaned.id, sha1('Remove ceil()'), 'region_deleted');
        await openRequest(page, orphaned.id);
        await captureScreen('Orphaned location', page.locator('[data-testid="location-info"][data-status="orphaned"]'));
    });

    test('A finished request shows its outcome and its changes', async ({ page, api, captureScreen }) => {
        const review = new FakeReview(api);

        const ready = review.seedLines(SMALL_FILE, 9, 12, 'Move the rounding of `total()` into its own method.', 'Extract the tax calculation');
        review.agentClaims(ready.id);
        linkExtractCommit(api, review, ready.id);
        review.agentLinksPullRequest(ready.id, PULL_REQUEST);
        review.githubChangesPullRequest(PULL_REQUEST, 'open', 'Extract TaxCalculation');
        review.agentMarksReady(ready.id, 'Extracted `tax()` and added a changelog entry.');
        await openRequest(page, ready.id);
        await captureScreen('Ready for review with a commit and a pull request', page.locator(`${DETAIL} [data-testid="pull-request-list"]`));

        const discussed = review.seedLines(SMALL_FILE, 3, 3, 'Is `strict_types` needed here?', 'Strict types in every file');
        review.agentClaims(discussed.id);
        for (const [question, answer] of DISCUSSION) {
            review.agentAsks(discussed.id, question);
            review.command(discussed.id, 'comments', { body: answer });
        }
        review.agentMarksReady(discussed.id, 'Added `declare(strict_types=1);` to the 14 files without it.');
        await openRequest(page, discussed.id);
        await captureScreen('Long conversation', page.locator(DETAIL_NEXT_STEP));

        await page.goto(`/requests/${ready.id}?tab=changes`);
        const fileDiffs = page.locator('[data-testid="commit-diff-file"]');
        await expect(fileDiffs).toHaveCount(2);
        for (const fileDiff of await fileDiffs.all()) {
            await fileDiff.scrollIntoViewIfNeeded();
            await expect(fileDiff.locator(DIFF_LINE).first()).toBeAttached();
        }
        await captureScreen('Changes tab', fileDiffs.first());

        const declined = review.seedGeneral('Move the tax rates into the database.', false, 'Tax rates in the database');
        review.agentClaims(declined.id);
        review.agentMarksWontDo(declined.id, 'The rates change once a year, and a deploy changes them faster than a migration.');
        await openRequest(page, declined.id);
        await captureScreen("Won't do by the agent", status(page, "Won't do"));

        const verified = review.seedLines(SYMBOL_FILE, 9, 9, 'Name the scale after its unit.');
        review.agentClaims(verified.id);
        review.agentMarksReady(verified.id, 'Renamed SCALE to BASIS_POINTS.');
        review.command(verified.id, 'verify', { note: 'Thanks.' });
        await openRequest(page, verified.id);
        await captureScreen('Verified', status(page, 'Verified'));

        const reopened = review.seedGeneral('Add a changelog entry.');
        review.agentClaims(reopened.id);
        review.agentMarksReady(reopened.id, 'Added.');
        review.command(reopened.id, 'reopen', { note: 'The entry names the wrong version.' });
        review.command(reopened.id, 'title', { title: 'Changelog entry for 2.4.0' });
        await openRequest(page, reopened.id);
        await captureScreen('Reopened', status(page, 'Reopened'));

        const closedReview = new FakeReview(api, { review: { title: 'Release 2.3' } });
        const closed = closedReview.seedLines(SMALL_FILE, 3, 3, 'Is strict_types needed here?');
        closedReview.close('Shipped in 2.3.');
        await openRequest(page, closed.id);
        await captureScreen('Request of a closed review', page.locator(`${DETAIL} [data-testid="review-closed"]`));
    });
});
