import type { BlameCommit } from '../../assets/api/types/blame';
import type { SearchHit } from '../../assets/api/types/search-hit';
import { now, searchHitList, sha1 } from '../ui/support/builders';
import { openFile, waitForHighlighting } from '../ui/support/codeView';
import { FakeReview } from '../ui/support/fakeReview';
import { codeViewFiles, NESTED_FILE, ROUND_FQN, SMALL_FILE, SYMBOL_FILE } from '../ui/support/files';
import { json } from '../ui/support/mockApi';
import { test } from './support/test';

const README = `# Billing

Invoice totals and tax rounding for the shop.

## Usage

1. Install the dependencies with \`composer install\`.
2. Run the tests with \`make test\`.

| Rate | Basis points |
|---|---|
| Standard | 1000 |
| Reduced | 500 |

\`\`\`php
$totals->total(net: 1000, taxBasisPoints: 1000);
\`\`\`

The [rounding rules](src/Billing/TaxRounding.php) round half up to whole cents.
`;

const HITS: SearchHit[] = [
    { kind: 'file', path: NESTED_FILE },
    { kind: 'symbol', path: SYMBOL_FILE, fqn: ROUND_FQN, symbolKind: 'method', declarationStartLine: 14 },
    { kind: 'file', path: SYMBOL_FILE },
];

function commit(name: string, authorName: string, daysAgo: number): BlameCommit {
    return { sha: sha1(name), authorName, authoredAt: now(-daysAgo * 86_400), summary: name };
}

const ADD_TOTALS = commit('Add invoice totals', 'Ada Lovelace', 500);

const ROUND_TAX = commit('Round tax per line', 'Grace Hopper', 3);

test.describe('CodeBrowser', () => {
    test('A reviewer reads the code and its blame, searches, and finishes the review', async ({ page, api, captureScreen }) => {
        const review = new FakeReview(api, { files: { ...codeViewFiles(), 'README.md': README } });
        review.viewedPaths = ['README.md'];
        api.on('GET', '/api/v1/reviews/:reviewId/search', () => json('lists.schema.json#/$defs/SearchHitList', searchHitList(review.id, HITS)));

        await page.goto(`/reviews/${review.id}`);
        await captureScreen('Directory with README', page.getByRole('region', { name: 'README.md' }).getByRole('table'));

        review.seedLines(SYMBOL_FILE, 9, 9, 'Name the scale after its unit, for example `BASIS_POINTS`.');
        review.seedLines(SYMBOL_FILE, 19, 22, 'Add a test for an amount of zero.');
        review.seedSymbol(SYMBOL_FILE, ROUND_FQN, 'Round half to even, as the tax office does.');
        await openFile(page, review, SYMBOL_FILE);
        await waitForHighlighting(page, 23);
        await captureScreen('File with line, range, and symbol threads', page.locator('[slot="annotation-17"] [data-testid="thread"]'));

        review.blames.set(SMALL_FILE, {
            ignoreRevsFile: false,
            ranges: [
                { startLine: 1, endLine: 8, commitSha: ADD_TOTALS.sha },
                { startLine: 9, endLine: 11, commitSha: ROUND_TAX.sha },
                { startLine: 12, endLine: 13, commitSha: ADD_TOTALS.sha },
            ],
            commits: [ADD_TOTALS, ROUND_TAX],
        });
        await page.goto(`/reviews/${review.id}/blame/${SMALL_FILE}`);
        await waitForHighlighting(page, 13);
        await captureScreen('Blame', page.locator('[data-testid="blame-block"][data-lines="9-11"]'));

        await page.keyboard.press('ControlOrMeta+k');
        await page.keyboard.type('tax');
        await captureScreen('Quick open', page.getByRole('dialog', { name: 'Quick open' }).getByRole('option').last());
        await page.keyboard.press('Escape');

        review.seedDraft({ kind: 'new_request', anchor: { kind: 'range', path: SMALL_FILE, range: { startLine: 9, endLine: 12 } }, body: 'Extract the tax calculation.' });
        review.seedDraft({ kind: 'new_request', anchor: null, body: 'Add a changelog entry.' });
        await openFile(page, review, SMALL_FILE);
        await waitForHighlighting(page, 13);
        await page.locator('[data-testid="finish-review"]').click();
        await page.locator('[data-testid="finish-review-form"] textarea[name="summary"]').fill('First pass over the billing code.');
        await captureScreen('Finish review with drafts', page.locator('[data-testid="finish-review-form"]'));
    });
});
