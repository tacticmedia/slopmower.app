import type { Locator, Page } from '@playwright/test';
import type { CommitDiffFile } from '../../assets/api/types/commit-diff';
import type { CommitLink } from '../../assets/api/types/commit-link';
import { addedFile, commitDiff, commitLink, modifiedFile, sha1 } from './support/builders';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE, SMALL_FILE_TEXT } from './support/files';
import { apiError, json, type MockApi, text } from './support/mockApi';
import { DETAIL_SIDEBAR, openRequest } from './support/requestScreens';
import { expect, test } from './support/test';

const BLOBS = '/api/v1/repositories/:repositoryId/blobs/:blobSha';

const DIFF_LINE = 'diffs-container [data-line-type]';

/** The small file with `tax` as the tax term on line 11. */
function smallFile(tax: string): string {
    return SMALL_FILE_TEXT.replace('intdiv($net * $taxBasisPoints, 10000)', tax);
}

const EXTRACTED = smallFile('$this->tax($net, $taxBasisPoints)');

/** An added text file of `lineCount` lines. */
function addedLines(path: string, lineCount: number): { file: CommitDiffFile; content: string } {
    const content = `${Array.from({ length: lineCount }, (_, index) => `${path} line ${index + 1}`).join('\n')}\n`;

    return { file: { ...addedFile(path, content), additions: lineCount }, content };
}

/** The agent links each commit to the request. Q9 lists the files of each commit, and R7 answers the contents. */
function linkCommits(api: MockApi, review: FakeReview, requestId: string, commits: [CommitLink, CommitDiffFile[]][], contents: string[]): void {
    const blobs = new Map(contents.map((content) => [sha1(content), content]));
    review.agentClaims(requestId);
    for (const [commit] of commits) {
        review.agentLinks(requestId, commit);
    }
    const diffs = new Map(commits.map(([commit, files]) => [commit.commitSha, commitDiff(review.find(requestId), commit, files)]));
    api.on('GET', '/api/v1/requests/:requestId/commits/:commitSha', ({ params }) => json('commit-diff.schema.json', diffs.get(params.commitSha ?? '')));
    api.on('GET', BLOBS, ({ params }) => {
        const blob = blobs.get(params.blobSha ?? '');

        return undefined === blob ? apiError(404, 'not_found', `No blob ${params.blobSha} exists.`) : text(blob);
    });
}

function fileDiff(page: Page, path: string): Locator {
    return page.locator(`[data-testid="commit-diff-file"][data-path="${path}"]`);
}

function blobReads(api: MockApi): string[] {
    return api.callsTo('GET', BLOBS).map(({ params }) => params.blobSha ?? '');
}

async function width(locator: Locator): Promise<number> {
    const found = await locator.boundingBox();
    if (null === found) {
        throw new Error(`${locator} is not visible.`);
    }

    return found.width;
}

test.describe('ChangesTab', () => {
    test('The Changes tab shows one FileDiff per linked commit with the anchored file first', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        const extract = commitLink(sha1('Extract tax()'), 'Extract tax()');
        const round = commitLink(sha1('Round the tax'), 'Round the tax');
        const [readme, movedReadme, changelog] = ['# Code view\n', '# Code view\n\nTax moved.\n', 'Round the tax.\n'];
        const rounded = smallFile('$this->roundedTax($net, $taxBasisPoints)');
        // Git lists the files in path order, and Q9 moves the anchored file up.
        linkCommits(
            api,
            review,
            request.id,
            [
                [extract, [modifiedFile('README.md', readme, movedReadme, false), modifiedFile(SMALL_FILE, SMALL_FILE_TEXT, EXTRACTED, true)]],
                [round, [addedFile('CHANGELOG.md', changelog), modifiedFile(SMALL_FILE, EXTRACTED, rounded, true)]],
            ],
            [readme, movedReadme, changelog, SMALL_FILE_TEXT, EXTRACTED, rounded],
        );
        review.agentMarksReady(request.id, 'Extracted and rounded the tax.');
        await openRequest(page, request.id);

        await page.locator('nav[aria-label="Request"] a').filter({ hasText: 'Changes' }).click();

        await expect(page).toHaveURL(/tab=changes/);
        await expect(page.locator('[data-testid="commit-changes"]')).toHaveCount(2);
        // Q3 breaks a tie of linkedAt by SHA, so the section order is not checked.
        for (const [commitSha, paths] of [
            [extract.commitSha, [SMALL_FILE, 'README.md']],
            [round.commitSha, [SMALL_FILE, 'CHANGELOG.md']],
        ] as const) {
            const fileDiffs = page.locator(`[data-testid="commit-changes"][data-commit-sha="${commitSha}"] [data-testid="commit-diff-file"]`);
            await expect(fileDiffs).toHaveCount(paths.length);
            expect(await fileDiffs.evaluateAll((elements) => elements.map((element) => element.getAttribute('data-path')))).toEqual(paths);
            for (let index = 0; index < paths.length; index++) {
                await expect(fileDiffs.nth(index).locator(DIFF_LINE).first()).toBeAttached();
            }
        }
    });

    test('A linked commit opens the Changes tab at its section', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        const changelog = addedLines('CHANGELOG.md', 120);
        const write = commitLink(sha1('Write the changelog'), 'Write the changelog');
        const extract = commitLink(sha1('Extract tax()'), 'Extract tax()');
        linkCommits(
            api,
            review,
            request.id,
            [
                [write, [changelog.file]],
                [extract, [modifiedFile(SMALL_FILE, SMALL_FILE_TEXT, EXTRACTED, true)]],
            ],
            [changelog.content, SMALL_FILE_TEXT, EXTRACTED],
        );
        await openRequest(page, request.id);

        await page.locator('[data-testid="commit-list"]').getByRole('link', { name: 'Extract tax()' }).click();

        // The changelog above loads after the link scrolled, and pushes the section down.
        await expect(fileDiff(page, 'CHANGELOG.md')).toBeAttached();
        await expect(page.locator(`[data-testid="commit-changes"][data-commit-sha="${extract.commitSha}"] h2`)).toBeInViewport();
    });

    test('Above 20 files, only the anchored file starts expanded, and a file loads when it is expanded', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        const notes = Array.from({ length: 20 }, (_, index) => `Note ${index}.\n`);
        const extract = commitLink(sha1('Extract tax()'), 'Extract tax()');
        const files = [...notes.map((note, index) => addedFile(`notes/${index}.md`, note)), modifiedFile(SMALL_FILE, SMALL_FILE_TEXT, EXTRACTED, true)];
        linkCommits(api, review, request.id, [[extract, files]], [...notes, SMALL_FILE_TEXT, EXTRACTED]);

        await page.goto(`/requests/${request.id}?tab=changes`);

        await expect(fileDiff(page, SMALL_FILE).locator(DIFF_LINE).first()).toBeAttached();
        expect(blobReads(api).toSorted()).toEqual([sha1(SMALL_FILE_TEXT), sha1(EXTRACTED)].toSorted());
        await fileDiff(page, 'notes/0.md').getByRole('button', { name: 'Toggle the diff' }).click();
        await expect(fileDiff(page, 'notes/0.md').locator(DIFF_LINE).first()).toBeAttached();
    });

    test('A file loads its diff when it scrolls into view', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        const chapters = Array.from({ length: 4 }, (_, index) => addedLines(`docs/${index}.md`, 100));
        const write = commitLink(sha1('Write the docs'), 'Write the docs');
        linkCommits(api, review, request.id, [[write, chapters.map(({ file }) => file)]], chapters.map(({ content }) => content));
        const last = fileDiff(page, 'docs/3.md');

        await page.goto(`/requests/${request.id}?tab=changes`);

        await expect(fileDiff(page, 'docs/0.md').locator(DIFF_LINE).first()).toBeAttached();
        expect(blobReads(api)).not.toContain(sha1(chapters.at(-1)?.content ?? ''));
        await last.scrollIntoViewIfNeeded();
        await expect(last.locator(DIFF_LINE).first()).toBeAttached();
    });

    test('At 1600 px the Changes tab spans the window without the sidebar, and the Conversation tab stays 1280 px wide with it', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        const extract = commitLink(sha1('Extract tax()'), 'Extract tax()');
        linkCommits(api, review, request.id, [[extract, [modifiedFile(SMALL_FILE, SMALL_FILE_TEXT, EXTRACTED, true)]]], [SMALL_FILE_TEXT, EXTRACTED]);
        await page.setViewportSize({ width: 1600, height: 900 });
        await openRequest(page, request.id);

        expect(await width(page.getByRole('main'))).toBeLessThanOrEqual(1280);
        await expect(page.locator(DETAIL_SIDEBAR)).toBeVisible();

        await page.locator('nav[aria-label="Request"] a').filter({ hasText: 'Changes' }).click();

        const section = page.locator('[data-testid="commit-changes"]');
        await expect(section).toBeVisible();
        expect(await width(section)).toBeGreaterThan(1280);
        await expect(page.locator(DETAIL_SIDEBAR)).toBeHidden();
    });
});
