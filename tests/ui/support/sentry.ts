import type { Page } from '@playwright/test';

/**
 * Sets the `<body>` attributes of `templates/base.html.twig` before the module script runs, and returns the payload of
 * each envelope item of the type that the page sends.
 */
export async function reportToSentry(page: Page, type: 'event' | 'feedback'): Promise<string[]> {
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
            if (line.startsWith(`{"type":"${type}"`) && undefined !== payload) {
                events.push(payload);
            }
        });

        return route.fulfill({ status: 200, headers: { 'access-control-allow-origin': '*' }, body: '{}' });
    });

    return events;
}
