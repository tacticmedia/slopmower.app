import type { Page } from '@playwright/test';
import { openFile } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { apiError } from './support/mockApi';
import { expect, test } from './support/test';

const SEARCH = '/api/v1/reviews/:reviewId/search';
const SEARCH_TEXT = 'secret-needle';

/**
 * Sets the `<body>` attributes of `templates/base.html.twig` before the module script runs, and returns the payload of
 * each error event that the page sends.
 */
async function reportToSentry(page: Page): Promise<string[]> {
    await page.addInitScript(() => {
        document.addEventListener('readystatechange', () => {
            if ('interactive' === document.readyState) {
                Object.assign(document.body.dataset, { release: 'test-release', sentryDsn: 'https://public@sentry.invalid/1', sentryEnvironment: 'ui-test' });
            }
        });
    });
    const events: string[] = [];
    await page.route('https://sentry.invalid/**', (route) => {
        // An envelope is a header line, then an item header line and a payload line per item.
        const lines = route.request().postData()?.split('\n') ?? [];
        lines.forEach((line, index) => {
            const payload = lines[index + 1];
            if (line.startsWith('{"type":"event"') && undefined !== payload) {
                events.push(payload);
            }
        });

        return route.fulfill({ status: 200, headers: { 'access-control-allow-origin': '*' }, body: '{}' });
    });

    return events;
}

test.describe('SentryReports', () => {
    test('A client error sends no event, and a server error sends one with the release and environment, and no search text or IP address', async ({ page, api }) => {
        const events = await reportToSentry(page);
        const review = new FakeReview(api);
        api.on('GET', SEARCH, (call) => (SEARCH_TEXT === call.query.get('q') ? apiError(500, 'internal_error', 'The index failed.') : apiError(422, 'invalid_input', 'The query is too short.')));
        await openFile(page, review, SMALL_FILE);
        await expect(page.locator('footer')).toHaveText('Release test-release');
        await page.keyboard.press('ControlOrMeta+k');
        const quickOpen = page.getByRole('dialog', { name: 'Quick open' });
        const field = quickOpen.getByRole('combobox', { name: 'File or symbol name' });

        await field.fill('x');
        await expect(quickOpen).toContainText('The query is too short.');
        await field.fill(SEARCH_TEXT);
        await expect(quickOpen).toContainText('The index failed.');

        await expect.poll(() => events.length).toBe(1);
        const payload = events[0] ?? '';
        const event = JSON.parse(payload);
        expect(event.exception.values[0].value).toBe('The index failed.');
        expect(event).toMatchObject({ release: 'test-release', environment: 'ui-test', sdk: { settings: { infer_ip: 'never' } } });
        expect(payload).toContain('/search?q=[Filtered]');
        expect(payload).not.toContain(SEARCH_TEXT);
    });
});
